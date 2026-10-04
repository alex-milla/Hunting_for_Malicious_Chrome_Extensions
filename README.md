# Chrome Extension Validator (versión hosting compartido: Apache + PHP)

Aplicación web que sustituye a los scripts PowerShell de validación de
extensiones de Chrome maliciosas. Extrae IDs de extensiones de un reporte de
indicadores (incluidos reportes crudos tipo Unit42 con prosa, tablas partidas,
installs, versiones y dominios C2 defangados), comprueba su estado y nombre
contra la Chrome Web Store, exporta un CSV listo para watchlists de Microsoft
Sentinel / Defender y, opcionalmente, sincroniza los hallazgos con un repo de
GitHub que actúa como blocklist público.

**Sin npm, sin Node, sin compilaciones**: HTML/CSS/JS planos + dos endpoints
PHP. Pensada para subirse por FTP al hosting compartido.

**Multilingüe**: Disponible en Español e Inglés. El idioma se selecciona automáticamente
según la preferencia del navegador o puede cambiarse manualmente desde el selector
en la interfaz.

```
Reporte (txt/csv) → extracción regex → api/check.php (Chrome Web Store)
                                    → CSV watchlist
                                    → api/sync.php → blocklist en GitHub
```

## Requisitos del hosting

- PHP 7.4 o superior.
- **cURL, `allow_url_fopen` o `fsockopen`** — con uno de los tres basta.
  El código prueba en ese orden y usa el primero disponible:
  - cURL (el habitual en la mayoría de hostings),
  - `file_get_contents` con `allow_url_fopen`,
  - `fsockopen` (socket directo; funciona en hostings donde los dos
    anteriores están desactivados, siempre que exista OpenSSL para HTTPS).

Sube `test-servidor.php` al hosting y ábrelo en el navegador para confirmarlo.
Te dirá en verde/rojo si el servidor vale y **qué método está usando
realmente**. **Bórralo después.**

## Instalación

1. Sube por FTP (o el administrador de archivos del hosting) el contenido de
   esta carpeta (`public_html/`, `htdocs/`, `www/`... como lo llame tu
   proveedor).
2. Renombra `config.sample.php` → **`config.php`** y ajusta los valores.
   - Solo `ADMIN_TOKEN` y `CACHE_*` hacen falta para el validador.
   - `GH_TOKEN` / `GH_REPO` solo para la sincronización con GitHub (opcional).
3. Comprueba que la carpeta `cache/` tiene permisos de escritura
   (755 o 775; el hosting suele darlos por defecto).
4. Abre tu dominio. Fin.

El `.htaccess` incluido bloquea el acceso web directo a `config.php` y a la
carpeta `cache/`, y desactiva el listado de directorios.

## Estructura

```
├── index.html               Interfaz (4 pasos) - multilingüe
├── assets/
│   ├── app.js               Lógica: extracción, cola, export, sync + i18n
│   └── style.css            Tema claro/oscuro (acento naranja #f97316)
├── api/
│   ├── _http.php            Helper HTTP: cURL → allow_url_fopen → fsockopen
│   ├── check.php            GET ?id=<ID> → consulta la Web Store (con caché 6 h)
│   └── sync.php             POST (cabecera x-admin-token) → commit blocklist en GitHub
├── locales/                 Archivos de traducción
│   ├── es.json               Traducciones en Español
│   └── en.json               Traducciones en Inglés
├── cache/                   Caché de comprobaciones (bloqueada por .htaccess)
├── config.sample.php        Plantilla de configuración
├── test-servidor.php        Diagnóstico (subir, probar y borrar)
├── test-sync.php            Diagnóstico de sincronización GitHub
├── test-sync-real.php       Prueba real de sincronización
├── .htaccess                Configuración Apache (bloquea config.php, cache/, locales/)
├── LICENSE                  Apache-2.0
├── NOTICE                   Atribución al proyecto original
```

## Configuración de la sincronización con GitHub (opcional)

1. Crea un repo público vacío para el blocklist.
2. Crea un token de grano fino en GitHub → Settings → Developer settings →
   Fine-grained tokens, con permiso **Contents: Read and write** SOLO sobre
   ese repo.
3. Rellena `GH_TOKEN`, `GH_REPO` y `GH_BRANCH` en `config.php`.
4. En la web, sección "4 · Blocklist en GitHub", introduce el `ADMIN_TOKEN`
   y pulsa Sincronizar.

Tras cada sync el repo contiene:

- `blocklist.csv` — `"ExtensionID","ExtensionName","Status","ChromeStoreURL"`
  (mismo formato que los CSV de PowerShell; BOM UTF-8 y CRLF).
- `blocklist.txt` — un ID por línea.

Consumibles públicamente en
`https://raw.githubusercontent.com/<usuario>/<repo>/<rama>/blocklist.csv`.

## Integración con Microsoft Sentinel / Defender

1. Exporta el CSV desde la web (o consume el `blocklist.csv` del repo).
2. Sentinel → Watchlists → New → sube el CSV → **SearchKey = `ExtensionID`**.
3. Caza en Defender para Endpoint (las extensiones viven en
   `%LOCALAPPDATA%\Google\Chrome\User Data\<perfil>\Extensions\<id>`):

```kusto
let ext_ids = _GetWatchlist('chrome-malicious-extensions')
| project SearchKey;
DeviceFileEvents
| where FolderPath has_any (ext_ids)
| summarize Count = count() by DeviceId, FolderPath
```

## Extracción de IDs

Los IDs de extensión de Chromium son **32 caracteres, solo letras `a-p`**
(codificación hexadecimal con el alfabeto desplazado). La web usa el patrón
`\b[a-p]{32}\b` con dedupe y orden alfabético — idéntico a
`extraer_indicadores.ps1` — que detecta los IDs en cualquier formato de
reporte sin falsos positivos con dominios, installs ni versiones.

## Soporte Multilingüe

La aplicación soporta **Español** e **Inglés** automáticamente:

- **Detección automática**: Usa el idioma del navegador (`navigator.language`)
- **Selector manual**: Dropdown en la barra superior para cambiar entre ES/EN
- **Persistencia**: La selección se guarda en `localStorage`

### Idiomas soportados
| Código | Idioma | Archivo |
|--------|--------|---------|
| es | Español | `locales/es.json` |
| en | English | `locales/en.json` |

### Añadir nuevos idiomas
1. Crea un nuevo archivo en `locales/` (ej: `fr.json`)
2. Copia la estructura de `en.json`
3. Traduce todos los valores
4. Añade la opción al selector en `index.html`
5. Actualiza `changeLanguage()` en `app.js` para manejar el nuevo idioma

Los IDs de traducción siguen el patrón de claves simples (ej: `step1_title`, `btn_extract`, etc.)

## Límites y notas

- Concurrencia de comprobación: 3 peticiones con 400 ms de espera entre cada
  una (equivale al rate limiting de 500 ms de los PowerShell). Los resultados
  Active/Removed se cachean 6 h en el servidor para no repetir consultas.
- En hostings compartidos, el tiempo máximo de ejecución de PHP
  (`max_execution_time`) no afecta: cada comprobación es una petición corta e
  independiente.
- Si dos sincronizaciones coinciden, la segunda devuelve un error de sha;
  basta con reintentarla.

## Licencia

Apache-2.0 (`LICENSE`). Los trabajos derivados deben conservar el archivo
`NOTICE` y mencionar este proyecto original.
