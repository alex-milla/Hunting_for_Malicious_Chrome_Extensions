# Chrome Extension Validator

A web application that replaces PowerShell scripts for validating malicious Chrome extensions. It extracts extension IDs from threat intelligence reports (including raw Unit42-style reports with prose, split tables, installs, versions, and defanged C2 domains), checks their status and names against the Chrome Web Store, exports a CSV ready for Microsoft Sentinel/Defender watchlists, and optionally synchronizes findings with a GitHub repository acting as a public blocklist.

**No npm, no Node, no build steps**: Plain HTML/CSS/JS + two PHP endpoints. Designed to be uploaded via FTP to shared hosting.

**Multilingual**: Available in English and Spanish. Language is automatically detected from browser preferences or can be manually switched from the interface selector.

```
Report (txt/csv) → regex extraction → api/check.php (Chrome Web Store)
                                    → CSV watchlist
                                    → api/sync.php → blocklist on GitHub
```

## Requirements

- PHP 7.4 or higher
- **cURL, `allow_url_fopen`, or `fsockopen`** — only one of these is needed.
  The code tests them in this order and uses the first available:
  - cURL (most common on shared hosting)
  - `file_get_contents` with `allow_url_fopen`
  - `fsockopen` (direct socket; works on hostings where the above are disabled, as long as OpenSSL is available for HTTPS)

Upload `test-servidor.php` to your hosting and open it in the browser to verify everything is working. It will show green/red status for each requirement and **which method is actually being used**. **Delete this file after testing.**

## Installation

1. Upload the contents of this folder (`public_html/`, `htdocs/`, `www/` - depending on your hosting provider) via FTP or your hosting file manager.
2. Rename `config.sample.php` → **`config.php`** and adjust the values.
   - Only `ADMIN_TOKEN` and `CACHE_*` are required for the validator to work.
   - `GH_TOKEN` / `GH_REPO` are only needed for GitHub synchronization (optional).
3. Ensure the `cache/` folder has write permissions (755 or 775; most hostings grant these by default).
4. Access your domain. That's it!

The included `.htaccess` file blocks direct web access to `config.php` and the `cache/` folder, and disables directory listing.

## Project Structure

```
.
├── index.html                    Multilingual interface (4 steps)
├── assets/
│   ├── app.js                    Logic: extraction, queue, export, sync + i18n
│   └── style.css                 Light/dark theme (orange accent #f97316)
├── api/
│   ├── _http.php                 HTTP helper: cURL → allow_url_fopen → fsockopen
│   ├── check.php                 GET ?id=<ID> → queries Web Store (6h cache)
│   └── sync.php                  POST (x-admin-token header) → commits to GitHub blocklist
├── locales/                      Translation files
│   ├── en.json                   English translations
│   └── es.json                   Spanish translations
├── cache/                        Check cache (blocked by .htaccess)
├── config.sample.php             Configuration template
├── test-servidor.php             Server diagnostic (upload, test, delete)
├── test-sync.php                 GitHub sync diagnostic
├── test-sync-real.php            Real sync test
├── .htaccess                     Apache configuration (blocks config.php, cache/, locales/)
├── LICENSE                       Apache-2.0
└── NOTICE                        Original project attribution
```

## GitHub Synchronization Setup (Optional)

To enable blocklist synchronization with GitHub:

1. Create an empty public repository for the blocklist (e.g., `Hunting_for_Malicious_Chrome_Extensions`)
2. Create a fine-grained Personal Access Token (PAT) at GitHub → Settings → Developer settings → Fine-grained tokens, with **Contents: Read and write** permission **ONLY** for that repository.
3. Fill in `GH_TOKEN`, `GH_REPO`, and `GH_BRANCH` in `config.php`.
4. In the web interface, section "4 · Blocklist on GitHub", enter your `ADMIN_TOKEN` and click Sync.

After each synchronization, the repository contains:

- `blocklist.csv` — `"ExtensionID","ExtensionName","Status","ChromeStoreURL"` (same format as PowerShell CSV exports; UTF-8 BOM with CRLF)
- `blocklist.txt` — one ID per line

These files are publicly consumable at:
`https://raw.githubusercontent.com/<user>/<repo>/<branch>/blocklist.csv`

## Microsoft Sentinel / Defender Integration

1. Export the CSV from the web interface (or consume `blocklist.csv` from the repo)
2. In Sentinel: Watchlists → New → upload the CSV → **SearchKey = `ExtensionID`**
3. Hunt in Defender for Endpoint (extensions are located at `%LOCALAPPDATA%\Google\Chrome\User Data\<profile>\Extensions\<id>`):

```kusto
let ext_ids = _GetWatchlist('chrome-malicious-extensions')
| project SearchKey;
DeviceFileEvents
| where FolderPath has_any (ext_ids)
| summarize Count = count() by DeviceId, FolderPath
```

## ID Extraction

Chrome extension IDs are **32 characters, using only letters a-p** (hexadecimal encoding with shifted alphabet). The web uses the pattern `\b[a-p]{32}\b` with deduplication and alphabetical sorting — identical to `extraer_indicadores.ps1` — which detects IDs in any report format without false positives from domains, installs, or versions.

## Multilingual Support

The application supports **English** and **Spanish** automatically:

- **Auto-detection**: Uses browser language (`navigator.language`)
- **Manual selector**: Dropdown in the top bar to switch between EN/ES
- **Persistence**: Selection is saved in `localStorage`

### Supported Languages

| Code | Language | File |
|------|----------|------|
| en | English | `locales/en.json` |
| es | Spanish | `locales/es.json` |

### Adding New Languages

1. Create a new file in `locales/` (e.g., `fr.json`)
2. Copy the structure from `en.json`
3. Translate all values
4. Add the option to the language selector in `index.html`
5. Update `changeLanguage()` in `app.js` to handle the new language

Translation keys follow a simple pattern (e.g., `step1_title`, `btn_extract`, etc.)

## Rate Limits and Notes

- Check concurrency: 3 requests with 400ms delay between each (equivalent to 500ms rate limiting from PowerShell scripts). Active/Removed results are cached for 6 hours on the server to avoid repeated queries.
- On shared hosting, PHP's `max_execution_time` doesn't affect this: each check is a short, independent request.
- If two synchronizations coincide, the second will return a sha error; simply retry.

## License

Apache-2.0 (`LICENSE`). Derivative works must retain the `NOTICE` file and mention this original project.

## Security Notes

- **Never commit `config.php`** to version control (it's in `.gitignore`)
- The `.htaccess` blocks web access to sensitive files
- All diagnostic test files can be safely uploaded (they contain no private data)
- The `cache/` directory only contains extension check results, not sensitive information

---

**📘 Spanish Documentation:** See [README_es.md](README_es.md)
