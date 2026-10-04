<?php
// Diagnóstico de la sincronización con GitHub.
// Súbelo a la raíz del hosting, ábrelo en el navegador (tudominio.com/test-sync.php)
// y pásale el resultado a quien te ayuda. NO muestra los tokens.
// Cuando termines, BÓRRALO del hosting.

header('Content-Type: text/html; charset=utf-8');

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    die('FALTA config.php en el hosting.');
}
require_once $configFile;

$httpFile = __DIR__ . '/api/_http.php';
if (!file_exists($httpFile)) {
    die('FALTA api/_http.php en el hosting.');
}
require_once $httpFile;

function row($label, $value, $ok) {
    $class = $ok ? 'ok' : 'bad';
    $mark  = $ok ? 'OK' : 'PROBLEMA';
    echo '<tr><td>' . htmlspecialchars($label) . '</td><td>' . htmlspecialchars($value) .
         '</td><td class="' . $class . '">' . $mark . '</td></tr>';
}

// ---- 1. Valores de config.php (sin mostrar secretos) ----
$adminOk = (defined('ADMIN_TOKEN') && ADMIN_TOKEN !== '' && ADMIN_TOKEN !== 'cambia-este-token');
$tokenOk = (defined('GH_TOKEN') && GH_TOKEN !== '');
$repoStr = (defined('GH_REPO') ? GH_REPO : '');
// Formato válido: usuario/repo (UNA sola barra; sin dominio, sin esquema)
$repoFormatOk = ($repoStr !== '' && preg_match('#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', $repoStr) === 1);
$branch = (defined('GH_BRANCH') && GH_BRANCH !== '') ? GH_BRANCH : 'main';

echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">' .
     '<title>Diagnóstico sync — Chrome Extension Validator</title>' .
     '<style>body{font-family:system-ui,sans-serif;background:#f8fafc;margin:40px;color:#111827}' .
     'table{border-collapse:collapse;background:#fff;width:100%;max-width:820px;box-shadow:0 1px 3px rgba(0,0,0,.1)}' .
     'th,td{padding:10px 14px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:15px}' .
     'th{background:#f1f5f9}td.ok{color:#15803d;font-weight:700}td.bad{color:#b91c1c;font-weight:700}' .
     'p{max-width:820px;color:#6b7280}</style></head><body>';
echo '<h1>Diagnóstico de la sincronización</h1>';
echo '<p>Este archivo NO muestra los valores de los tokens. Bórralo del hosting cuando termines.</p>';
echo '<table><tr><th>Comprobación</th><th>Resultado</th><th>Estado</th></tr>';

row('ADMIN_TOKEN en config.php',
    $adminOk ? 'Definido y distinto del valor de fábrica' : 'Vacío o sigue siendo "cambia-este-token"',
    $adminOk);
row('GH_TOKEN en config.php',
    $tokenOk ? 'Presente (' . strlen(GH_TOKEN) . ' caracteres · empieza por ' . substr(GH_TOKEN, 0, 7) . '... · TERMINA EN ' . substr(GH_TOKEN, -4) . ')' : 'Vacío',
    $tokenOk);
row('GH_REPO en config.php',
    $repoStr === '' ? 'Vacío' : ($repoFormatOk ? $repoStr : 'Formato incorrecto: debe ser usuario/repo (sin https://, sin github.com/, una sola barra)'),
    $repoFormatOk);
row('GH_BRANCH', $branch, true);

// ---- 2. ¿El token de GitHub ve el repositorio? ----
// Base owner/repo sin romper la barra (rawurlencode por segmento)
$repoBase = implode('/', array_map('rawurlencode', explode('/', $repoStr)));

$repoVisible = false;
$pushPerm = false;
$repoMsg = '';
$apiUsed = '';
if ($tokenOk && $repoFormatOk) {
    list($status, $body, $err, $apiUsed) = http_request('GET',
        'https://api.github.com/repos/' . $repoBase,
        array('headers' => array(
            'Authorization' => 'Bearer ' . GH_TOKEN,
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'chrome-extension-validator',
        )));
    $data = json_decode($body, true);
    if ($status === 200 && is_array($data) && isset($data['full_name'])) {
        $repoVisible = true;
        $repoMsg = 'HTTP 200 · ' . $data['full_name'] . ' (' . (isset($data['private']) && $data['private'] ? 'privado' : 'público') . ')';
        if (isset($data['permissions']['push'])) { $pushPerm = (bool)$data['permissions']['push']; }
    } else {
        $repoMsg = 'HTTP ' . $status . ' · ' .
            (is_array($data) && isset($data['message']) ? $data['message'] : $err);
    }
    row('GitHub ve el repositorio', $repoMsg . ' · método: ' . $apiUsed, $repoVisible);

    if ($repoVisible) {
        row('Permiso de escritura (push) del token', $pushPerm ? 'Sí, puede commitear' : 'NO tiene permiso Contents: Read and write sobre este repo', $pushPerm);
    }
} else {
    row('GitHub ve el repositorio', 'No comprobado: corrige antes GH_TOKEN y/o GH_REPO', false);
}

// ---- 3. ¿Existe ya blocklist.csv en el repo? ----
if ($repoVisible && $pushPerm) {
    list($s2, $b2) = http_request('GET',
        'https://api.github.com/repos/' . $repoBase . '/contents/blocklist.csv?ref=' . rawurlencode($branch),
        array('headers' => array(
            'Authorization' => 'Bearer ' . GH_TOKEN,
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'chrome-extension-validator',
        )));
    if ($s2 === 200) {
        $data2 = json_decode($b2, true);
        $size = (is_array($data2) && isset($data2['size'])) ? $data2['size'] : 0;
        row('blocklist.csv en el repo', 'Ya existe (' . $size . ' bytes) · la sincronización fusionará sobre él', true);
    } elseif ($s2 === 404) {
        row('blocklist.csv en el repo', 'No existe todavía: la primera sincronización lo creará (o sube tu latest.csv renombrado)', true);
    } else {
        row('blocklist.csv en el repo', 'HTTP ' . $s2 . ' (¿seguro que la rama "' . $branch . '" existe?)', false);
    }
}

echo '</table>';
?>
<h2 style="margin-top:28px">Prueba del POST de sincronización (NO escribe nada en el repo)</h2>
<p style="max-width:820px;color:#6b7280">
Escribe tu ADMIN_TOKEN y pulsa el botón. Si todo funciona, debe responder
<strong>HTTP 400</strong> con <code>{"error":"no-results"}</code> — eso demuestra que el
camino completo (POST + cabecera de token + PHP) llega hasta el final sin romperse.
Tabla de arriba en verde + esto en 400 = la sincronización real funcionará.
</p>
<input id="probe-token" type="password" placeholder="ADMIN_TOKEN de config.php"
       style="padding:8px;border:1px solid #ccc;border-radius:6px;width:280px">
<button onclick="probe()" style="padding:8px 16px;cursor:pointer">Probar api/sync.php</button>
<pre id="probe-out" style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;white-space:pre-wrap;max-width:820px">(resultado aquí)</pre>
<script>
function probe() {
  var out = document.getElementById('probe-out');
  var token = document.getElementById('probe-token').value.trim();
  out.textContent = 'Probando...';
  fetch('api/sync.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'x-admin-token': token },
    body: JSON.stringify({ results: [] })
  })
    .then(function (r) {
      return r.text().then(function (t) { return { status: r.status, text: t }; });
    })
    .then(function (res) {
      out.textContent = 'HTTP ' + res.status + '\n\n' + (res.text || '(sin cuerpo)');
    })
    .catch(function (e) { out.textContent = 'FALLO DE RED: ' + e; });
}
</script>
</body>
</html>
