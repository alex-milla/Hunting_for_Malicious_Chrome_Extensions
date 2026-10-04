# Chrome Extension Validator

Aplicación web que sustituye a los scripts PowerShell de validación de extensiones de Chrome maliciosas. Extrae IDs de extensiones de un reporte de indicadores (incluidos reportes crudos tipo Unit42 con prosa, tablas partidas, installs, versiones y dominios C2 defangados), comprueba su estado y nombre contra la Chrome Web Store, exporta un CSV listo para watchlists de Microsoft Sentinel/Defender y, opcionalmente, sincroniza los hallazgos con un repositorio de GitHub que actúa como blocklist público.

**Sin npm, sin Node, sin compilaciones**: HTML/CSS/JS planos + dos endpoints PHP. Pensada para subirse por FTP al hosting compartido.

**Multilingüe**: Disponible en Español e Inglés. El idioma se selecciona automáticamente según la preferencia del navegador o puede cambiarse manualmente desde el selector en la interfaz.

```
Reporte (txt/csv) → extracción regex → api/check.php (Chrome Web Store)
                                    → CSV watchlist
                                    → api/sync.php → blocklist en GitHub
```

## Requisitos del Hosting

- PHP 7.4 o superior
- **cURL, `allow_url_fopen` o `fsockopen`** — con uno de los tres basta.
  El código prueba en ese orden y usa el primero disponible:
  - cURL (el habitual en la mayoría de hostings)
  - `file_get_contents` con `allow_url_fopen`
  - `fsockopen` (socket directo; funciona en hostings donde los dos anteriores están desactivados, siempre que exista OpenSSL para HTTPS)

Sube `test-servidor.php` al hosting y ábrelo en el navegador para confirmarlo. Te dirá en verde/rojo si el servidor vale y **qué método está usando realmente**. **Bórralo después.**

## Instalación

1. Sube por FTP (o el administrador de archivos del hosting) el contenido de esta carpeta (`public_html/`, `htdocs/`, `www/`... como lo llame tu proveedor).
2. Renombra `config.sample.php` → **`config.php`** y ajusta los valores.
   - Solo `ADMIN_TOKEN` y `CACHE_*` hacen falta para el validador.
   - `GH_TOKEN` / `GH_REPO` solo para la sincronización con GitHub (opcional).
3. Comprueba que la carpeta `cache/` tiene permisos de escritura (755 o 775; el hosting suele darlos por defecto).
4. Abre tu dominio. ¡Listo!

El archivo `.htaccess` incluido bloquea el acceso web directo a `config.php` y a la carpeta `cache/`, y desactiva el listado de directorios.

## Estructura del Proyecto

```
.
├── index.html                    Interfaz (4 pasos) - multilingüe
├── assets/
│   ├── app.js                    Lógica: extracción, cola, export, sync + i18n
│   └── style.css                 Tema claro/oscuro (acento naranja #f97316)
├── api/
│   ├── _http.php                 Helper HTTP: cURL → allow_url_fopen → fsockopen
│   ├── check.php                 GET ?id=<ID> → consulta la Web Store (con caché 6 h)
│   └── sync.php                  POST (cabecera x-admin-token) → commit blocklist en GitHub
├── locales/                      Archivos de traducción
│   ├── es.json                   Traducciones en Español
│   └── en.json                   Traducciones en Inglés
├── cache/                        Caché de comprobaciones (bloqueada por .htaccess)
├── config.sample.php             Plantilla de configuración
├── test-servidor.php             Diagnóstico del servidor (subir, probar y borrar)
├── test-sync.php                 Diagnóstico de sincronización GitHub
├── test-sync-real.php            Prueba real de sincronización
├── .htaccess                     Configuración Apache (bloquea config.php, cache/, locales/)
├── LICENSE                       Apache-2.0
└── NOTICE                        Atribución al proyecto original
```

## Configuración de Sincronización con GitHub (Opcional)

Para habilitar la sincronización del blocklist con GitHub:

1. Crea un repositorio público vacío para el blocklist (ej: `Hunting_for_Malicious_Chrome_Extensions`)
2. Crea un token de grano fino (Fine-grained PAT) en GitHub → Configuración → Configuración de desarrollador → Tokens de grano fino, con permiso **Contents: Read and write** **SOLO** sobre ese repositorio.
3. Completa `GH_TOKEN`, `GH_REPO` y `GH_BRANCH` en `config.php`.
4. En la interfaz web, sección "4 · Blocklist en GitHub", introduce tu `ADMIN_TOKEN` y haz clic en Sincronizar.

Tras cada sincronización, el repositorio contendrá:

- `blocklist.csv` — `"ExtensionID","ExtensionName","Status","ChromeStoreURL"` (mismo formato que los CSV de PowerShell; BOM UTF-8 y CRLF)
- `blocklist.txt` — un ID por línea

Estos archivos son consumibles públicamente en:
`https://raw.githubusercontent.com/<usuario>/<repo>/<rama>/blocklist.csv`

## Integración con Microsoft Sentinel / Defender

1. Exporta el CSV desde la interfaz web (o consume `blocklist.csv` del repositorio)
2. En Sentinel: Watchlists → Nuevo → sube el CSV → **SearchKey = `ExtensionID`**
3. Busca en Defender para Endpoint (las extensiones están en `%LOCALAPPDATA%\Google\Chrome\User Data\<perfil>\Extensions\<id>`):

```kusto
let ext_ids = _GetWatchlist('chrome-malicious-extensions')
| project SearchKey;
DeviceFileEvents
| where FolderPath has_any (ext_ids)
| summarize Count = count() by DeviceId, FolderPath
```

## Extracción de IDs

Los IDs de extensión de Chromium son **32 caracteres, solo letras a-p** (codificación hexadecimal con el alfabeto desplazado). La aplicación usa el patrón `\b[a-p]{32}\b` con deduplicación y orden alfabético — idéntico a `extraer_indicadores.ps1` — que detecta los IDs en cualquier formato de reporte sin falsos positivos con dominios, installs o versiones.

## Soporte Multilingüe

La aplicación soporta **Español** e **Inglés** automáticamente:

- **Detección automática**: Usa el idioma del navegador (`navigator.language`)
- **Selector manual**: Menú desplegable en la barra superior para cambiar entre ES/EN
- **Persistencia**: La selección se guarda en `localStorage`

### Idiomas Soportados

| Código | Idioma | Archivo |
|--------|--------|---------|
| es | Español | `locales/es.json` |
| en | English | `locales/en.json` |

### Añadir Nuevos Idiomas

1. Crea un nuevo archivo en `locales/` (ej: `fr.json`)
2. Copia la estructura de `en.json`
3. Traduce todos los valores
4. Añade la opción al selector en `index.html`
5. Actualiza `changeLanguage()` en `app.js` para manejar el nuevo idioma

Las claves de traducción siguen un patrón simple (ej: `step1_title`, `btn_extract`, etc.)

## Límites y Notas

- Concurrencia de comprobación: 3 peticiones con 400ms de espera entre cada una (equivalente al rate limiting de 500ms de los scripts PowerShell). Los resultados Active/Removed se cachean 6 horas en el servidor para no repetir consultas.
- En hostings compartidos, el tiempo máximo de ejecución de PHP (`max_execution_time`) no afecta: cada comprobación es una petición corta e independiente.
- Si dos sincronizaciones coinciden, la segunda devolverá un error de sha; basta con reintentarla.

## Licencia

Apache-2.0 (`LICENSE`). Los trabajos derivados deben conservar el archivo `NOTICE` y mencionar este proyecto original.

## Notas de Seguridad

- **Nunca subas `config.php`** a control de versiones (ya está en `.gitignore`)
- El archivo `.htaccess` bloquea el acceso web a archivos sensibles
- Todos los archivos de prueba de diagnóstico pueden subirse con seguridad (no contienen datos privados)
- La carpeta `cache/` solo contiene resultados de comprobaciones de extensiones, no información sensible

---

**📘 Documentación en Inglés:** Ver [README.md](README.md)
