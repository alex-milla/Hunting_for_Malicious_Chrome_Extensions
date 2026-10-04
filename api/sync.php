<?php
// Endpoint: POST api/sync.php
// Fusiona los resultados comprobados de la sesion con el blocklist del repo
// de GitHub (blocklist.csv + blocklist.txt) usando la API REST de GitHub.
//
// Seguridad: requiere cabecera "x-admin-token" igual a ADMIN_TOKEN (config.php).
// GH_TOKEN (PAT de grano fino, contents:write SOLO sobre el repo blocklist)
// vive en config.php, bloqueado al acceso web por .htaccess.

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    // 409 (no 503): Cloudflare sustituye el cuerpo de los errores 5xx
    // por su propia pagina, y el mensaje real nunca llegaria al navegador
    http_response_code(409);
    echo json_encode(array(
        'error' => 'not-configured',
        'message' => 'Falta config.php: copia config.sample.php y renombralo',
    ));
    exit;
}
require_once $configFile;

foreach (array('ADMIN_TOKEN', 'GH_TOKEN', 'GH_REPO') as $var) {
    if (!defined($var) || constant($var) === '') {
        http_response_code(409);
        echo json_encode(array(
            'error' => 'not-configured',
            'message' => 'Falta la variable ' . $var . ' en config.php',
        ));
        exit;
    }
}

$admin = isset($_SERVER['HTTP_X_ADMIN_TOKEN']) ? (string)$_SERVER['HTTP_X_ADMIN_TOKEN'] : '';
if (!hash_equals(ADMIN_TOKEN, $admin)) {
    http_response_code(401);
    echo json_encode(array('error' => 'unauthorized'));
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['results']) || !is_array($body['results'])) {
    http_response_code(400);
    echo json_encode(array('error' => 'invalid-json'));
    exit;
}

$incoming = array();
foreach ($body['results'] as $r) {
    if (!is_array($r) || !isset($r['id'])) { continue; }
    $rid = strtolower(trim((string)$r['id']));
    if (!preg_match('/^[a-p]{32}$/', $rid)) { continue; }
    $incoming[$rid] = array(
        'ExtensionID'    => $rid,
        'ExtensionName'  => (!empty($r['name'])) ? (string)$r['name'] : 'Unknown',
        'Status'         => (!empty($r['status'])) ? (string)$r['status'] : 'Unknown',
        'ChromeStoreURL' => (!empty($r['url'])) ? (string)$r['url'] : 'https://chromewebstore.google.com/detail/' . $rid,
    );
}

if (count($incoming) === 0) {
    http_response_code(400);
    echo json_encode(array('error' => 'no-results'));
    exit;
}

$repo   = GH_REPO;
$branch = (defined('GH_BRANCH') && GH_BRANCH !== '') ? GH_BRANCH : 'main';

// Helper de peticiones HTTP: cURL → allow_url_fopen → fsockopen
require_once __DIR__ . '/_http.php';

// Construye la parte owner/repo de la URL sin romper la barra:
// rawurlencode() sobre la cadena completa convertiria "/" en %2F y
// GitHub responderia 404 Not Found.
function gh_repo_base($repo) {
    $parts = explode('/', $repo);
    return rawurlencode(trim($parts[0])) . '/' .
           rawurlencode(trim(isset($parts[1]) ? $parts[1] : ''));
}

function gh_get_contents($repo, $branch, $path) {
    // Devuelve array($sha, $texto)
    $url = 'https://api.github.com/repos/' . gh_repo_base($repo) . '/contents/' . $path . '?ref=' . rawurlencode($branch);
    list($status, $body, $err) = http_request('GET', $url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . GH_TOKEN,
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'chrome-extension-validator',
        ),
    ));
    if ($status === 404) { return array(null, ''); }
    if ($status !== 200) {
        throw new Exception('GitHub ' . $status . ' al leer ' . $path . ' ' . $err);
    }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['content'])) {
        throw new Exception('Respuesta inesperada de GitHub al leer ' . $path);
    }
    $sha = isset($data['sha']) ? $data['sha'] : null;
    $text = base64_decode(str_replace(array("\n", "\r"), '', $data['content']));
    return array($sha, (string)$text);
}

function gh_put_contents($repo, $branch, $path, $content, $sha, $message) {
    $url = 'https://api.github.com/repos/' . gh_repo_base($repo) . '/contents/' . $path;
    $payload = array(
        'message' => $message,
        'content' => base64_encode($content),
        'branch'  => $branch,
    );
    if ($sha !== null) { $payload['sha'] = $sha; }

    list($status, $body, $err) = http_request('PUT', $url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . GH_TOKEN,
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'chrome-extension-validator',
            'Content-Type'  => 'application/json',
        ),
        'body' => json_encode($payload),
    ));
    if ($status === 0 || $status >= 300) {
        $data = json_decode($body, true);
        $msg = (is_array($data) && !empty($data['message'])) ? $data['message'] : ('GitHub ' . $status . ' al escribir ' . $path . ' ' . $err);
        throw new Exception($msg);
    }
    return json_decode($body, true);
}

function csv_field($v) {
    return '"' . str_replace('"', '""', (string)$v) . '"';
}

function parse_csv_rows($text) {
    // Devuelve array asociativo id => fila, con soporte de BOM y comillas
    $rows = array();
    $header = null;
    $lines = preg_split('/\r\n|\r|\n/', $text);
    foreach ($lines as $line) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', trim($line));
        if ($line === '') { continue; }
        $fields = str_getcsv($line);
        if ($header === null) { $header = array_map('trim', $fields); continue; }
        $row = array();
        for ($i = 0; $i < count($header); $i++) {
            $key = $header[$i];
            $row[$key] = (isset($fields[$i])) ? $fields[$i] : '';
        }
        if (!empty($row['ExtensionID'])) { $rows[$row['ExtensionID']] = $row; }
    }
    return $rows;
}

try {
    list($csvSha, $csvText) = gh_get_contents($repo, $branch, 'blocklist.csv');
    list($txtSha) = gh_get_contents($repo, $branch, 'blocklist.txt');

    // Fusion: los datos nuevos (check reciente) sobrescriben los existentes
    $rows = parse_csv_rows($csvText);
    $added = 0;
    $updated = 0;
    foreach ($incoming as $rid => $item) {
        if (isset($rows[$rid])) { $updated++; } else { $added++; }
        $rows[$rid] = $item;
    }
    ksort($rows);

    // CSV: mismo formato que los PowerShell (todo entrecomillado, BOM, CRLF)
    $csvLines = array(implode(',', array_map('csv_field', array('ExtensionID', 'ExtensionName', 'Status', 'ChromeStoreURL'))));
    foreach ($rows as $rid => $r) {
        $csvLines[] = implode(',', array_map('csv_field', array(
            $r['ExtensionID'], $r['ExtensionName'], $r['Status'], $r['ChromeStoreURL'],
        )));
    }
    $csvContent = "\xEF\xBB\xBF" . implode("\r\n", $csvLines) . "\r\n";
    $txtContent = implode("\r\n", array_keys($rows)) . "\r\n";

    $message = 'sync: +' . $added . ' / ~' . $updated . ' extensiones (total ' . count($rows) . ') [Chrome Extension Validator]';
    $commit = gh_put_contents($repo, $branch, 'blocklist.csv', $csvContent, $csvSha, $message);
    gh_put_contents($repo, $branch, 'blocklist.txt', $txtContent, $txtSha, $message);

    $commitUrl = null;
    if (is_array($commit) && isset($commit['commit']['html_url'])) {
        $commitUrl = $commit['commit']['html_url'];
    }

    echo json_encode(array(
        'ok'        => true,
        'added'     => $added,
        'updated'   => $updated,
        'total'     => count($rows),
        'commitUrl' => $commitUrl,
    ));
} catch (Exception $e) {
    // 422 (no 502): Cloudflare sustituye el cuerpo de los 502 por su pagina
    // de error y el mensaje de GitHub se perderia
    http_response_code(422);
    echo json_encode(array('error' => 'sync-failed', 'message' => $e->getMessage()));
}
