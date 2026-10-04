<?php
// Endpoint: GET api/check.php?id=<ID>
// Consulta la Chrome Web Store desde el servidor (sin restricción CORS)
// y devuelve JSON: { id, name, status, url }
// Estados: Active | Removed | Unknown | Error  (mismos valores que los PowerShell)
//
// Las peticiones HTTP salen vía api/_http.php, que prueba en orden:
// cURL → allow_url_fopen → fsockopen (funciona sin los dos primeros).

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    http_response_code(503);
    echo json_encode(array(
        'error' => 'not-configured',
        'message' => 'Falta config.php: copia config.sample.php y renombralo',
    ));
    exit;
}
require_once $configFile;

if (!defined('CACHE_DIR')) { define('CACHE_DIR', __DIR__ . '/../cache'); }
if (!defined('CACHE_TTL')) { define('CACHE_TTL', 21600); } // 6 horas

require_once __DIR__ . '/_http.php';

$id = strtolower(trim(isset($_GET['id']) ? $_GET['id'] : ''));
if (!preg_match('/^[a-p]{32}$/', $id)) {
    http_response_code(400);
    echo json_encode(array('error' => 'invalid extension id (32 chars, a-p)'));
    exit;
}

$storeUrl = 'https://chromewebstore.google.com/detail/' . $id;
$result = null;

// Cache de resultados estables (Active/Removed)
$cacheFile = CACHE_DIR . '/' . $id . '.json';
if (is_file($cacheFile)) {
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($cached) && isset($cached['_ts']) && (time() - $cached['_ts']) < CACHE_TTL) {
        unset($cached['_ts']);
        echo json_encode($cached);
        exit;
    }
}

list($status, $html, $err) = http_request('GET', $storeUrl, array(
    'headers' => array(
        'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept-Language' => 'en-US,en;q=0.9',
        'Accept'          => 'text/html,application/xhtml+xml',
    ),
    'timeout' => 20,
));

if ($status === 0) {
    // Sin conexion (todos los metodos HTTP fallaron)
    $result = array('id' => $id, 'name' => 'Error/Unavailable', 'status' => 'Error', 'url' => $storeUrl);
} elseif ($status === 404) {
    $result = array('id' => $id, 'name' => 'Removed/NotFound', 'status' => 'Removed', 'url' => $storeUrl);
} elseif (preg_match('/consent\.google\.com|Before you continue/i', $html)) {
    // Google redirige a la pagina de consentimiento de cookies
    $result = array('id' => $id, 'name' => 'Blocked (consent page)', 'status' => 'Error', 'url' => $storeUrl);
} elseif (preg_match('/ItemNotFound|item-not-found/i', $html)) {
    $result = array('id' => $id, 'name' => 'Removed/NotFound', 'status' => 'Removed', 'url' => $storeUrl);
} elseif (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
    $name = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = preg_replace('/[-\x{2013}]\s*Chrome Web Store.*$/iu', '', $name);
    $name = preg_replace('/[-\x{2013}]\s*Chrome \x{30A6}\x{30A7}\x{30D6}\x{30B9}\x{30C8}\x{30A2}.*$/iu', '', $name);
    $name = trim((string)$name);
    $result = array(
        'id'     => $id,
        'name'   => ($name !== '' ? $name : 'Unknown'),
        'status' => 'Active',
        'url'    => $storeUrl,
    );
} else {
    $result = array('id' => $id, 'name' => 'Unknown', 'status' => 'Unknown', 'url' => $storeUrl);
}

// Guardar en cache solo resultados estables
if ($result['status'] === 'Active' || $result['status'] === 'Removed') {
    if (!is_dir(CACHE_DIR)) { @mkdir(CACHE_DIR, 0755, true); }
    @file_put_contents($cacheFile, json_encode($result + array('_ts' => time())));
}

echo json_encode($result);
