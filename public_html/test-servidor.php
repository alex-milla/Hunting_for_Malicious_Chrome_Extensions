<?php
// Diagnóstico del servidor: sube este archivo (y la carpeta api/) a tu
// hosting, ábrelo en el navegador (tudominio.com/test-servidor.php) y
// comprueba que todo dice OK. Cuando termines, BÓRRALO del hosting.

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/api/_http.php';

// Prueba real contra la Chrome Web Store (MetaMask: extensión conocida y activa)
$storeUsed = '';
list($storeStatus, $storeHtml) = http_request('GET', 'https://chromewebstore.google.com/detail/nkbihfbeogaeaoehlefnkodbefgpgknn', array(
    'headers' => array(
        'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept-Language' => 'en-US,en;q=0.9',
    ),
), $storeUsed);
$storeTitle = '';
if (preg_match('/<title>(.*?)<\/title>/is', $storeHtml, $m)) {
    $storeTitle = trim($m[1]);
}

// Prueba contra la API de GitHub
$ghUsed = '';
list($ghStatus) = http_request('GET', 'https://api.github.com/rate_limit', array(
    'headers' => array('User-Agent' => 'chrome-extension-validator'),
), $ghUsed);

$curlOk    = function_exists('curl_init');
$fopenOk   = ((bool)ini_get('allow_url_fopen'));
$fsockOk   = function_exists('fsockopen');
$opensslOk = extension_loaded('openssl');
$anyMethod = $curlOk || $fopenOk || $fsockOk;
$httpWorks = ($storeStatus === 200 && $storeTitle !== '');

function row($label, $value, $ok, $alwaysOk = false) {
    $class = $ok ? 'ok' : 'bad';
    $mark  = $ok ? 'OK' : 'PROBLEMA';
    if ($alwaysOk) { $mark = $ok ? 'OK' : 'No (habrá fallback)'; $class = $ok ? 'ok' : 'warn'; }
    echo '<tr><td>' . htmlspecialchars($label) . '</td><td>' . htmlspecialchars($value) . '</td><td class="' . $class . '">' . $mark . '</td></tr>';
}

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Diagnóstico del servidor — Chrome Extension Validator</title>
<style>
  body { font-family: system-ui, sans-serif; background: #f8fafc; margin: 40px; color: #111827; }
  table { border-collapse: collapse; background: #fff; width: 100%; max-width: 800px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  th, td { padding: 10px 14px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 15px; }
  th { background: #f1f5f9; }
  td.ok { color: #15803d; font-weight: 700; }
  td.warn { color: #a16207; font-weight: 700; }
  td.bad { color: #b91c1c; font-weight: 700; }
  h1 { font-size: 22px; }
  p { max-width: 800px; }
  p.nota { color: #6b7280; }
  .todo-bien { color: #15803d; font-weight: 700; }
  .fatal { color: #b91c1c; font-weight: 700; }
</style>
</head>
<body>
<h1>Diagnóstico del servidor</h1>
<p class="nota">Cuando termines la comprobación, borra este archivo (test-servidor.php) del hosting.</p>
<table>
  <tr><th>Comprobación</th><th>Resultado</th><th>Estado</th></tr>
  <?php
    row('Versión de PHP', PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>='));
    row('Extensión cURL', $curlOk ? 'Disponible (método principal)' : 'No disponible (se probarán los métodos alternativos)', $curlOk, true);
    row('allow_url_fopen', $fopenOk ? 'Activo (método alternativo 1)' : 'Desactivado (se probará el método alternativo 2)', $fopenOk, true);
    row('fsockopen', $fsockOk ? 'Disponible (método alternativo 2, sin cURL ni allow_url_fopen)' : 'No disponible', $fsockOk, true);
    row('OpenSSL (para HTTPS)', $opensslOk ? 'Disponible' : 'No disponible (necesario para conectarse por HTTPS)', $opensslOk);
    row('Conexión a la Chrome Web Store',
        'HTTP ' . $storeStatus . ' · método usado: ' . $storeUsed . ' · título: ' . ($storeTitle !== '' ? $storeTitle : '(sin título)'),
        $httpWorks);
    row('Conexión a la API de GitHub', 'HTTP ' . $ghStatus . ' · método usado: ' . $ghUsed, ($ghStatus === 200));
  ?>
</table>

<?php if (!$anyMethod): ?>
  <p class="fatal">
    Tu hosting no tiene NINGUNA de las tres formas de conectarse a Internet
    (sin cURL, sin allow_url_fopen y sin fsockopen). Opciones:
    (1) contacta con tu proveedor para que active alguna,
    (2) revisa si tu panel de control (cPanel/Plesk) tiene "Seleccionar versión
    de PHP" donde puedes marcar la extensión curl,
    o (3) cambia a un plan/hosting que lo permita.
  </p>
<?php elseif (!$httpWorks && $anyMethod): ?>
  <p class="fatal">
    Hay algún método disponible (<?php echo htmlspecialchars($storeUsed); ?>) pero la
    conexión a la Chrome Web Store falló (HTTP <?php echo (int)$storeStatus; ?>).
    Revisa que OpenSSL esté activo o pégale este resultado a quien te ayuda con el proyecto.
  </p>
<?php else: ?>
  <p class="todo-bien">
    Todo en orden: la web puede consultar la Chrome Web Store usando el método
    "<?php echo htmlspecialchars($storeUsed); ?>". Ya puedes usar el validador.
  </p>
<?php endif; ?>
</body>
</html>
