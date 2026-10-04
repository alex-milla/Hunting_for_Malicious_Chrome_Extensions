"use strict";

/* Chrome Extension Validator - lógica cliente (sin frameworks, sin build)
   1. Extrae IDs de extensión ([a-p]{32}) del texto o archivo pegado.
   2. Consulta api/check.php con concurrencia limitada.
   3. Exporta CSV/TXT y sincroniza el blocklist vía api/sync.php.
   4. Soporte multilingüe (es/en) */

var ID_PATTERN = /\b[a-p]{32}\b/gi;
var CONCURRENCY = 3;
var DELAY_MS = 400;

// Estados
var STATUS_LABEL = {
  Active: "Active",
  Removed: "Removed",
  Unknown: "Unknown",
  Error: "Error",
};

// Traducciones - se cargan dinámicamente
var i18n = {
  t: function (key, params) {
    var str = i18n.translations[key] || key;
    if (params) {
      for (var k in params) {
        str = str.replace(new RegExp("\\{" + k + "\\}", "g"), params[k]);
      }
    }
    return str;
  },
  translations: {},
  language: "es",
  
  load: function (lang) {
    return fetch("locales/" + lang + ".json")
      .then(function (resp) {
        if (!resp.ok) { throw new Error("Locale file not found"); }
        return resp.json();
      })
      .then(function (data) {
        i18n.translations = data;
        i18n.language = lang;
        i18n.updateDocumentLanguage();
        return data;
      })
      .catch(function () {
        // Fallback a español si falla
        i18n.translations = window.defaultTranslations || {};
        i18n.language = "es";
        return i18n.translations;
      });
  },
  
  updateDocumentLanguage: function () {
    document.documentElement.lang = i18n.language;
    document.documentElement.dir = i18n.translations.direction || "ltr";
    if (window.updateAllText) {
      window.updateAllText();
    }
  }
};

// Estado de la aplicación
var state = {
  ids: [],
  results: new Map(),
  running: false,
  paused: false,
  abortRequested: false,
  filter: "all",
  functionsWarned: false,
};

var rowMap = new Map();

function $(id) { return document.getElementById(id); }
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

function storeUrl(id) {
  return "https://chromewebstore.google.com/detail/" + id;
}

function pad(n) { return String(n).padStart(2, "0"); }

function timestamp() {
  var d = new Date();
  return d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) +
    "_" + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds());
}

function showEl(id) { $(id).classList.remove("hidden"); }
function hideEl(id) { $(id).classList.add("hidden"); }

var bannerTimer = null;
function showToast(msg, sticky) {
  var b = $("banner");
  b.textContent = msg;
  b.classList.remove("hidden");
  if (bannerTimer) { clearTimeout(bannerTimer); bannerTimer = null; }
  if (!sticky) { bannerTimer = setTimeout(function () { b.classList.add("hidden"); }, 9000); }
}
function hideBanner() { $("banner").classList.add("hidden"); }

function downloadBlob(content, filename, type) {
  var blob = new Blob([content], { type: type });
  var url = URL.createObjectURL(blob);
  var a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

// Función para actualizar todos los textos de la interfaz
function updateAllText() {
  if (!i18n.translations) return;
  
  var t = i18n.t;
  
  // Header
  $("app-title").textContent = t("title");
  $("footer-text").textContent = t("footer");
  
  // Step 1
  $("step1-title").textContent = t("step1_title");
  $("step1-label").textContent = t("step1_label");
  $("input-text").placeholder = t("step1_placeholder");
  $("step1-hint").textContent = t("step1_hint");
  $("btn-open-file-label").textContent = t("btn_open_file");
  $("btn-extract").textContent = t("btn_extract");
  
  // Step 2
  $("step2-title").textContent = t("step2_title");
  $("btn-check").textContent = t("btn_check");
  $("btn-copy-ids").textContent = t("btn_copy_ids");
  $("btn-export-ids").textContent = t("btn_export_txt");
  
  // Step 3
  $("step3-title").textContent = t("step3_title");
  $("tab-all").textContent = t("tab_all") + " ";
  $("tab-active").textContent = t("tab_active") + " ";
  $("tab-removed").textContent = t("tab_removed") + " ";
  $("tab-error").textContent = t("tab_error") + " ";
  $("tab-pending").textContent = t("tab_pending") + " ";
  $("btn-export-csv").textContent = t("btn_export_csv");
  $("csv-hint").textContent = t("csv_hint");
  
  // Table headers
  $("th-pos").textContent = t("table_header_pos");
  $("th-id").textContent = t("table_header_id");
  $("th-name").textContent = t("table_header_name");
  $("th-status").textContent = t("table_header_status");
  $("th-url").textContent = t("table_header_url");
  
  // Step 4
  $("step4-title").textContent = t("step4_title");
  $("step4-hint").textContent = t("step4_hint");
  $("stat-extracted-label").textContent = t("stat_extracted");
  $("stat-checked-label").textContent = t("stat_checked");
  $("stat-active-label").textContent = t("stat_active");
  $("stat-removed-label").textContent = t("stat_removed");
  $("label-admin-token").textContent = t("label_admin_token");
  $("admin-token").placeholder = t("placeholder_admin_token");
  $("btn-sync").textContent = t("btn_sync");
  $("sync-hint").textContent = t("sync_hint");
  
  // Botones de control
  $("btn-pause").textContent = t("btn_pause");
  $("btn-cancel").textContent = t("btn_cancel");
  $("theme-toggle").title = t("theme_toggle_title");
  
  // Actualizar herramienta de tema
  $("language-select").title = t("language") + " / " + (i18n.language === "es" ? "English" : "Español");
}

// Cargar locale al inicio
var userLang = localStorage.getItem("language") || navigator.language || "es";
var lang = userLang.startsWith("es") ? "es" : "en";

// Cargar el locale antes de que el DOM esté listo
var localePromise = i18n.load(lang);

// Guarda el language en localStorage cuando se selecciona
function changeLanguage(lang) {
  localStorage.setItem("language", lang);
  i18n.load(lang).then(function () {
    updateAllText();
    // Si hay resultados, actualizar la tabla
    if (state.ids.length > 0) {
      renderTable();
      updateCounters();
    }
  });
}

/* ---------- Extracción ---------- */

function extractIds() {
  var text = $("input-text").value;
  var found = new Set();
  var m;
  ID_PATTERN.lastIndex = 0;
  while ((m = ID_PATTERN.exec(text)) !== null) {
    found.add(m[0].toLowerCase());
  }
  state.ids = Array.from(found).sort();
  state.results.clear();
  rowMap.clear();
  state.filter = "all";
  setFilterButtons();
  $("results-body").innerHTML = "";
  hideEl("step-results");
  hideEl("step-blocklist");

  if (state.ids.length === 0) {
    hideEl("step-extract");
    showToast(i18n.t("toast_no_ids"));
    return;
  }
  $("extract-summary").textContent = i18n.t("step2_summary", { count: state.ids.length });
  showEl("step-extract");
}

function copyIds() {
  navigator.clipboard
    .writeText(state.ids.join("\n"))
    .then(function () { showToast(i18n.t("toast_ids_copied")); });
}

function exportTxt() {
  downloadBlob(
    state.ids.join("\r\n") + "\r\n",
    "extensiones_extraidas_" + timestamp() + ".txt",
    "text/plain;charset=utf-8"
  );
}

/* ---------- Comprobación contra la Web Store ---------- */

function warnServerProblem() {
  if (state.functionsWarned) { return; }
  state.functionsWarned = true;
  showToast(i18n.t("toast_server_problem"), true);
}

function checkOne(id) {
  var fallback = { id: id, name: i18n.t("status_error"), status: "Error", url: storeUrl(id) };
  return fetch("api/check.php?id=" + id)
    .then(function (resp) {
      if (resp.status === 503) {
        warnServerProblem();
        return fallback;
      }
      if (resp.status === 404) {
        warnServerProblem();
        return fallback;
      }
      return resp.json()
        .then(function (data) {
          return {
            id: id,
            name: (data && data.name) ? data.name : i18n.t("status_unknown"),
            status: (data && data.status) ? data.status : "Unknown",
            url: (data && data.url) ? data.url : storeUrl(id),
          };
        })
        .catch(function () {
          warnServerProblem();
          return fallback;
        });
    })
    .catch(function () { return fallback; });
}

function runChecks() {
  if (state.running) { return Promise.resolve(); }

  var pending = state.ids.filter(function (id) { return !state.results.has(id); });
  if (pending.length === 0) {
    showEl("step-results");
    showToast(i18n.t("toast_all_checked"));
    return Promise.resolve();
  }

  state.running = true;
  state.paused = false;
  state.abortRequested = false;
  $("btn-check").disabled = true;
  showEl("step-results");
  showEl("btn-pause");
  showEl("btn-cancel");
  $("btn-pause").textContent = i18n.t("btn_pause");

  renderTable();
  updateCounters();
  updateProgress();
  updateBlocklistStats();

  var index = 0;

  function worker() {
    function next() {
      if (state.abortRequested) { return Promise.resolve(); }
      if (state.paused) { return sleep(150).then(next); }
      var i = index++;
      if (i >= pending.length) { return Promise.resolve(); }
      var id = pending[i];
      return checkOne(id).then(function (result) {
        state.results.set(id, result);
        updateRow(id);
        updateCounters();
        updateProgress();
        updateBlocklistStats();
        return sleep(DELAY_MS).then(next);
      });
    }
    return next();
  }

  var workers = [];
  for (var w = 0; w < CONCURRENCY; w++) { workers.push(worker()); }

  return Promise.all(workers).then(function () {
    state.running = false;
    hideEl("btn-pause");
    hideEl("btn-cancel");
    $("btn-check").disabled = false;
    if (state.abortRequested) {
      var remaining = state.ids.filter(function (id) { return !state.results.has(id); }).length;
      if (remaining > 0) {
        showToast(i18n.t("toast_csv_partial", { count: state.results.size, remaining: remaining }));
      }
    }
  });
}

/* ---------- Render ---------- */

function buildRow(id, pos) {
  var tr = document.createElement("tr");

  var tdPos = document.createElement("td");
  tdPos.className = "pos";
  tdPos.textContent = pos;

  var tdId = document.createElement("td");
  var btnId = document.createElement("button");
  btnId.className = "ext-id";
  btnId.title = i18n.t("theme_toggle_title"); // Reusamos el tooltip
  btnId.textContent = id;
  btnId.addEventListener("click", function () {
    navigator.clipboard.writeText(id).then(function () {
      showToast(i18n.t("id_copied", { id: id }));
    });
  });
  tdId.appendChild(btnId);

  var tdName = document.createElement("td");
  tdName.className = "name";

  var tdStatus = document.createElement("td");

  var tdUrl = document.createElement("td");
  tdUrl.className = "url";

  tr.appendChild(tdPos);
  tr.appendChild(tdId);
  tr.appendChild(tdName);
  tr.appendChild(tdStatus);
  tr.appendChild(tdUrl);
  fillRow(tr, id);
  return tr;
}

function fillRow(tr, id) {
  var r = state.results.get(id);
  var tdName = tr.querySelector(".name");
  var tdStatus = tr.children[3];
  var tdUrl = tr.querySelector(".url");

  if (!r) {
    tdName.textContent = "—";
    tdName.className = "name dash";
    tdStatus.innerHTML = '<span class="pill pending">' + i18n.t("status_pending") + '</span>';
    tdUrl.textContent = "";
    return;
  }
  tdName.className = "name";
  tdName.textContent = r.name;
  var statusLabel = STATUS_LABEL[r.status] || r.status;
  // Usar la traducción si existe
  var translatedStatus = i18n.t("status_" + r.status.toLowerCase()) || statusLabel;
  tdStatus.innerHTML = '<span class="pill ' + r.status + '">' + translatedStatus + '</span>';
  tdUrl.innerHTML = "";
  var a = document.createElement("a");
  a.href = r.url;
  a.target = "_blank";
  a.rel = "noreferrer";
  a.textContent = i18n.t("table_header_url"); // "Abrir" / "Open"
  tdUrl.appendChild(a);
}

function matchesFilter(id) {
  var r = state.results.get(id);
  switch (state.filter) {
    case "all": return true;
    case "pending": return !r;
    case "error": return !!r && (r.status === "Error" || r.status === "Unknown");
    default: return !!r && r.status === state.filter;
  }
}

function renderTable() {
  var tbody = $("results-body");
  tbody.innerHTML = "";
  rowMap.clear();
  state.ids.forEach(function (id, idx) {
    if (!matchesFilter(id)) { return; }
    var tr = buildRow(id, idx + 1);
    rowMap.set(id, tr);
    tbody.appendChild(tr);
  });
}

function insertRowSorted(tr, id) {
  var tbody = $("results-body");
  var pos = state.ids.indexOf(id);
  var ref = null;
  for (var j = pos + 1; j < state.ids.length; j++) {
    ref = rowMap.get(state.ids[j]);
    if (ref) { break; }
  }
  tbody.insertBefore(tr, ref);
}

function updateRow(id) {
  var inFilter = matchesFilter(id);
  var tr = rowMap.get(id);
  if (!inFilter) {
    if (tr) { tr.remove(); rowMap.delete(id); }
    return;
  }
  if (!tr) {
    var newTr = buildRow(id, state.ids.indexOf(id) + 1);
    rowMap.set(id, newTr);
    insertRowSorted(newTr, id);
  } else {
    fillRow(tr, id);
  }
}

function updateCounters() {
  var active = 0, removed = 0, errors = 0, pending = 0;
  state.ids.forEach(function (id) {
    var r = state.results.get(id);
    if (!r) { pending++; }
    else if (r.status === "Active") { active++; }
    else if (r.status === "Removed") { removed++; }
    else { errors++; }
  });
  $("count-all").textContent = state.ids.length;
  $("count-active").textContent = active;
  $("count-removed").textContent = removed;
  $("count-error").textContent = errors;
  $("count-pending").textContent = pending;
}

function updateProgress() {
  var total = state.ids.length;
  var done = state.results.size;
  var pct = total ? Math.round((done / total) * 100) : 0;
  $("progress-fill").style.width = pct + "%";
  $("progress-text").textContent = i18n.t("progress_text", { done: done, total: total, percent: pct });
}

function setFilterButtons() {
  var tabs = document.querySelectorAll("#filters .tab");
  tabs.forEach(function (btn) {
    btn.classList.toggle("active", btn.dataset.filter === state.filter);
  });
}

/* ---------- Export CSV ---------- */

function csvEscape(v) {
  var s = String(v == null ? "" : v);
  return '"' + s.replace(/"/g, '""') + '"';
}

function exportCsv() {
  var rows = state.ids
    .filter(function (id) { return matchesFilter(id) && state.results.has(id); })
    .map(function (id) { return state.results.get(id); });

  if (rows.length === 0) {
    showToast(i18n.t("toast_no_csv_results"));
    return;
  }
  var header = [i18n.t("table_header_id"), i18n.t("table_header_name"), i18n.t("table_header_status"), i18n.t("table_header_url")].map(csvEscape).join(",");
  var lines = rows.map(function (r) {
    var statusLabel = STATUS_LABEL[r.status] || r.status;
    var translatedStatus = i18n.t("status_" + r.status.toLowerCase()) || statusLabel;
    return [r.id, r.name, translatedStatus, r.url].map(csvEscape).join(",");
  });
  downloadBlob(
    "\uFEFF" + [header].concat(lines).join("\r\n") + "\r\n",
    "malicious_extensions_enriched_" + timestamp() + ".csv",
    "text/csv;charset=utf-8"
  );
  var remaining = state.ids.length - state.results.size;
  if (remaining > 0) {
    showToast(i18n.t("toast_csv_partial", { count: rows.length, remaining: remaining }));
  }
}

/* ---------- Blocklist GitHub ---------- */

function updateBlocklistStats() {
  var checked = Array.from(state.results.values()).filter(function (r) {
    return r.status === "Active" || r.status === "Removed";
  });
  if (checked.length === 0) {
    hideEl("step-blocklist");
    return;
  }
  showEl("step-blocklist");
  $("bl-extracted").textContent = state.ids.length;
  $("bl-checked").textContent = checked.length;
  $("bl-active").textContent = checked.filter(function (r) { return r.status === "Active"; }).length;
  $("bl-removed").textContent = checked.filter(function (r) { return r.status === "Removed"; }).length;
}

function showSyncMsg(type, text) {
  var el = $("sync-msg");
  el.className = "msg " + type;
  el.textContent = text;
}

function syncBlocklist() {
  var token = $("admin-token").value.trim();
  if (!token) {
    showSyncMsg("error", i18n.t("toast_no_token"));
    return;
  }
  var checked = Array.from(state.results.values()).filter(function (r) {
    return r.status === "Active" || r.status === "Removed";
  });
  if (checked.length === 0) {
    showSyncMsg("error", i18n.t("toast_no_checked"));
    return;
  }

  $("btn-sync").disabled = true;
  showSyncMsg("ok", i18n.t("msg_sync_syncing"));

  fetch("api/sync.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "x-admin-token": token },
    body: JSON.stringify({ results: checked }),
  })
    .then(function (resp) {
      return resp.text().then(function (rawText) {
        var data = {};
        try { data = JSON.parse(rawText); } catch (e) { data = {}; }
        return { status: resp.status, data: data, raw: rawText };
      });
    })
    .then(function (res) {
      if (res.status === 200 && res.data.ok) {
        showSyncMsg("ok", i18n.t("toast_sync_success", {
          added: res.data.added,
          updated: res.data.updated,
          total: res.data.total
        }) + (res.data.commitUrl ? " Commit: " + res.data.commitUrl : ""));
        $("admin-token").value = "";
      } else if (res.status === 401) {
        showSyncMsg("error", i18n.t("toast_sync_error_token"));
      } else if (res.status === 503 || res.data.error === "not-configured") {
        showSyncMsg("error", i18n.t("toast_sync_error_config"));
      } else {
        var detail = res.data.message || res.data.error || ("HTTP " + res.status);
        if (!res.data.message && !res.data.error && res.raw) {
          detail += "\n\n" + i18n.t("toast_sync_error_generic", { error: res.raw.substring(0, 400) });
        }
        showSyncMsg("error", i18n.t("toast_sync_error_generic", { error: detail }));
      }
    })
    .catch(function (e) {
      showSyncMsg("error", i18n.t("toast_sync_error_generic", { error: e }));
    })
    .finally(function () {
      $("btn-sync").disabled = false;
    });
}

/* ---------- Eventos ---------- */

function readFile(f) {
  if (!f) { return; }
  var reader = new FileReader();
  reader.onload = function () { $("input-text").value = String(reader.result || ""); };
  reader.readAsText(f);
}

function init() {
  var ta = $("input-text");

  ta.addEventListener("dragover", function (e) {
    e.preventDefault();
    ta.classList.add("dragging");
  });
  ta.addEventListener("dragleave", function () { ta.classList.remove("dragging"); });
  ta.addEventListener("drop", function (e) {
    e.preventDefault();
    ta.classList.remove("dragging");
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      readFile(e.dataTransfer.files[0]);
    }
  });

  $("file-input").addEventListener("change", function (e) {
    if (e.target.files && e.target.files[0]) { readFile(e.target.files[0]); }
    e.target.value = "";
  });

  $("btn-extract").addEventListener("click", extractIds);
  $("btn-check").addEventListener("click", function () { runChecks(); });
  $("btn-copy-ids").addEventListener("click", copyIds);
  $("btn-export-ids").addEventListener("click", exportTxt);
  $("btn-export-csv").addEventListener("click", exportCsv);
  $("btn-sync").addEventListener("click", syncBlocklist);

  $("btn-pause").addEventListener("click", function () {
    state.paused = !state.paused;
    $("btn-pause").textContent = state.paused ? i18n.t("btn_resume") : i18n.t("btn_pause");
  });
  $("btn-cancel").addEventListener("click", function () {
    state.abortRequested = true;
    state.paused = false;
  });

  var tabs = document.querySelectorAll("#filters .tab");
  tabs.forEach(function (btn) {
    btn.addEventListener("click", function () {
      state.filter = btn.dataset.filter;
      setFilterButtons();
      renderTable();
    });
  });

  $("theme-toggle").addEventListener("click", function () {
    var dark = document.documentElement.classList.toggle("dark");
    localStorage.setItem("theme", dark ? "dark" : "light");
  });

  $("banner").addEventListener("click", hideBanner);

  // Selector de idioma
  $("language-select").value = lang;
  $("language-select").addEventListener("change", function () {
    changeLanguage(this.value);
  });

  // Actualizar texto después de cargar el locale
  updateAllText();

  // Verificar si hay hash de idioma en la URL
  var hashLang = window.location.hash.substring(1);
  if (hashLang === "es" || hashLang === "en") {
    changeLanguage(hashLang);
    $("language-select").value = hashLang;
  }
}

// Esperar a que el DOM esté listo
document.addEventListener("DOMContentLoaded", init);

// También cargar el locale cuando el DOM esté listo para asegurar que todo esté disponible
if (typeof i18n !== "undefined") {
  localePromise.then(updateAllText);
}
