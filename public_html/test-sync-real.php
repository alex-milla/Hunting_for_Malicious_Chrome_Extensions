<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prueba real de sincronización — Chrome Extension Validator</title>
<style>
  body { font-family: system-ui, sans-serif; background: #f8fafc; margin: 40px; color: #111827; }
  h1 { font-size: 22px; }
  p { max-width: 820px; }
  input { padding: 8px; border: 1px solid #ccc; border-radius: 6px; width: 320px; margin-right: 10px; margin-bottom: 10px; font-family: monospace; }
  input[type=password] { font-family: inherit; width: 320px; }
  button { padding: 8px 16px; cursor: pointer; border-radius: 6px; border: none; background: #f97316; color: #fff; font-weight: 600; }
  pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; white-space: pre-wrap; max-width: 820px; min-height: 60px; }
  .nota { color: #6b7280; }
</style>
</head>
<body>
<h1>Prueba real de sincronización (con UNA extensión)</h1>
<p class="nota">
Pega el ID de UNA extensión de tu reporte y tu ADMIN_TOKEN. Al pulsar el botón:
(1) se comprueba en la Web Store, (2) se manda a api/sync.php como en la sincronización
real, (3) se muestra la respuesta CRUDA del servidor y los segundos que ha tardado.
Si sale bien, esa extensión queda añadida al blocklist (es una entrada real, no un dato falso).
<b>Borra este archivo del hosting al terminar.</b>
</p>

<label>Extension ID (32 caracteres, a-p)</label><br>
<input id="ext-id" placeholder="p. ej. nkbihfbeogaeaoehlefnkodbefgpgknn">
<br>
<label>ADMIN_TOKEN</label><br>
<input id="admin-token2" type="password" placeholder="ADMIN_TOKEN de config.php">
<br><br>
<button onclick="probeReal()">Probar sincronización real</button>

<pre id="probe-out2">(resultado aquí)</pre>

<script>
function probeReal() {
  var id = document.getElementById('ext-id').value.trim().toLowerCase();
  var token = document.getElementById('admin-token2').value.trim();
  var out = document.getElementById('probe-out2');

  if (!/^[a-p]{32}$/.test(id)) {
    out.textContent = 'ID no valido: deben ser 32 caracteres, solo letras a-p.';
    return;
  }
  out.textContent = '1/2 Comprobando en la Web Store... (cronometro en marcha)';
  var t0 = Date.now();

  fetch('api/check.php?id=' + id)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      out.textContent = '1/2 Web Store: ' + data.status + ' · ' + data.name +
        '\n\n2/2 Sincronizando con GitHub...';
      if (data.status !== 'Active' && data.status !== 'Removed') {
        out.textContent += '\n\nNo se sincroniza: la extension no es Active/Removed (' +
          data.status + '). Prueba con otro ID.';
        return null;
      }
      return fetch('api/sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-admin-token': token },
        body: JSON.stringify({ results: [data] })
      })
        .then(function (r) {
          return r.text().then(function (t) { return { status: r.status, text: t }; });
        })
        .then(function (res) {
          var secs = ((Date.now() - t0) / 1000).toFixed(1);
          out.textContent = 'Terminado en ' + secs + ' segundos\n\nHTTP ' + res.status +
            '\n\n' + (res.text || '(sin cuerpo)');
        });
    })
    .catch(function (e) {
      out.textContent = 'FALLO: ' + e;
    });
}
</script>
</body>
</html>
