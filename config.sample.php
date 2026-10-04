<?php
// Copia este archivo como config.php (sin la extensión .sample) y ajusta
// los valores. El .htaccess incluido bloquea el acceso web a config.php.

// Token que la web pide para autorizar la sincronización del blocklist.
// Elige uno largo y aleatorio (p. ej. 32+ caracteres).
define('ADMIN_TOKEN', 'cambia-este-token');

// PAT de grano fino de GitHub con permiso "Contents: Read and write"
// SOBRE el repositorio del blocklist. Se crea en:
// GitHub → Settings → Developer settings → Fine-grained tokens
define('GH_TOKEN', '');

// Repositorio destino del blocklist, en formato usuario/repo
define('GH_REPO', '');

// Rama a commitear (opcional; por defecto 'main')
define('GH_BRANCH', 'main');

// Caché de comprobaciones (no hace falta tocar)
define('CACHE_DIR', __DIR__ . '/cache');
define('CACHE_TTL', 21600); // 6 horas
