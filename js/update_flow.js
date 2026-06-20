/* global OC */
/**
 * Shared "🔄 Actualizar" flow — loaded on every page (Portfolio, Analytics,
 * Dividends, Settings, Glossary) so the button works in-place from anywhere
 * instead of bouncing the user to Portfolio first.
 *
 * Self-contained: on DOMContentLoaded it
 *   1) Reads `data-route-*` attrs from `#gbm-app` (every page already exposes
 *      index/update; this module also needs `data-route-update`, which the
 *      analytics/dividends/glossary templates now inject too).
 *   2) Injects the MFA + toast + progress-bar HTML into the page if it's not
 *      already there (Portfolio's main.php carries the markup verbatim; the
 *      other pages get it via this injector to avoid duplicating PHP partials).
 *   3) Wires `#update-btn` (and `#docs-btn`, if present) to the same handlers
 *      that used to live only in dashboard.js — `updateData()` / `submitTotp()`
 *      / `postUpdate()` / etc.
 *
 * Portfolio's `js/dashboard.js` already has its own copy of these handlers
 * (verbatim port from upstream), so on Portfolio we DON'T run this module's
 * init — `dashboard.js` does it. We detect that by checking for an
 * `[data-update-flow-owner="page"]` attribute on `#gbm-app`; main.php sets it.
 */
(function () {
'use strict';

// Exposed for diagnostic poking from devtools; tests rely on the page-level
// behaviour, not on internals being on `window`.
const NS = 'UpdateFlow';
if (window[NS] && window[NS].__loaded) return;

let routes = null;

// ============ Modal/toast/progress-bar HTML injection ============
const MODAL_HTML = (
  '<!-- injected by update_flow.js -->\n' +
  '<div id="progress-bar" class="progress-bar"></div>\n' +
  '<div id="toast" class="toast">\n' +
  '  <button id="toast-close-btn" class="t-close" aria-label="Close">×</button>\n' +
  '  <div class="t-title"><span class="spin"></span> <span id="toast-title">Actualizando tu portafolio</span></div>\n' +
  '  <div class="t-stage" id="progress-stage">Conectando con GBM…</div>\n' +
  '</div>\n' +
  '<div id="totp-modal" class="modal-backdrop">\n' +
  '  <div class="modal">\n' +
  '    <h3>🔐 GBM+ Security Code</h3>\n' +
  '    <p>Your session expired. GBM+ needs to verify it\'s you.</p>\n' +
  '    <div class="hint">\n' +
  '      📱 <strong>Open the GBM+ app</strong> on your phone — GBM+ just pushed a código de 6 dígitos.<br>\n' +
  '      El código cambia cada 30 segundos.\n' +
  '    </div>\n' +
  '    <input type="text" id="totp-input" inputmode="numeric" pattern="[0-9]*" maxlength="6"\n' +
  '           autocomplete="one-time-code"\n' +
  '           data-lpignore="true" data-1p-ignore data-bwignore placeholder="000000">\n' +
  '    <div id="totp-error" class="err-msg"></div>\n' +
  '    <label for="totp-full-reload"\n' +
  '           style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;\n' +
  '                  background:rgba(255,255,255,0.03); border:1px solid var(--border);\n' +
  '                  border-radius:10px; padding:12px 14px; margin-top:14px; margin-bottom:6px;\n' +
  '                  font-size:13px; color:var(--muted); line-height:1.45;">\n' +
  '      <input type="checkbox" id="totp-full-reload"\n' +
  '             style="margin-top:2px; width:18px; height:18px; accent-color:#3b82f6; flex-shrink:0;">\n' +
  '      <span>\n' +
  '        <strong style="color:var(--text);">Recargar todo desde cero</strong> — wipe the local cache\n' +
  '        (portfolio + transaction history) and re-download everything from GBM+.<br>\n' +
  '        <span style="opacity:.8;">Use this if the numbers look off. Takes ~1–3 min.\n' +
  '        Your login is kept; you only enter the code once.</span>\n' +
  '      </span>\n' +
  '    </label>\n' +
  '    <div class="modal-actions">\n' +
  '      <button id="totp-cancel" class="btn-cancel">Cancelar</button>\n' +
  '      <button id="totp-submit" class="btn-submit">Actualizar</button>\n' +
  '    </div>\n' +
  '  </div>\n' +
  '</div>\n'
);

function injectModalsIfMissing() {
  if (document.getElementById('totp-modal')) return;          // Portfolio already has it
  const root = document.getElementById('gbm-app') || document.body;
  const holder = document.createElement('div');
  holder.id = 'update-flow-injected';
  holder.innerHTML = MODAL_HTML;
  // Append at the end of #gbm-app so CSS scoped under `#gbm-app .modal-backdrop`
  // still wins. (All modal/toast/progress-bar CSS is already scoped that way.)
  root.appendChild(holder);
}

// ============ Button helpers ============
const updateBtn = () => document.getElementById('update-btn');
function setUpdateBtn(loading, label) {
  const b = updateBtn();
  if (!b) return;
  b.disabled = loading;
  b.classList.toggle('loading', loading);
  const labelEl = b.querySelector('.label');
  if (labelEl) labelEl.textContent = label || 'Actualizar';
  else b.textContent = '🔄 ' + (label || 'Actualizar');
}

// Tiny inline status helper. Most pages don't have an #update-status element
// (only the legacy portfolio shape did), so this is best-effort — show via
// the toast for the others.
function showStatus(kind, msg) {
  // Re-use the toast as a non-blocking status surface.
  const t = document.getElementById('toast');
  if (t) {
    t.classList.remove('ok', 'err');
    if (kind) t.classList.add(kind);
    const title = document.getElementById('toast-title');
    const stage = document.getElementById('progress-stage');
    if (title) title.textContent = msg || '';
    if (stage) stage.textContent = '';
    t.classList.add('active');
    if (kind === 'ok') setTimeout(() => t.classList.remove('active'), 3000);
  }
}

// ============ Toast / progress bar ============
function showToast(stage, kind) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.classList.remove('ok', 'err');
  if (kind) t.classList.add(kind);
  const stageEl = document.getElementById('progress-stage');
  // Skip the DOM write when the text hasn't changed — the 500 ms
  // progress poll calls this with the same stage for minutes at a
  // time during long fetches.
  if (stageEl && stageEl.textContent !== stage) stageEl.textContent = stage;
  t.classList.add('active');
}
function setToastTitle(title) {
  const el = document.getElementById('toast-title');
  if (el) el.textContent = title;
}
function hideToast() {
  const t = document.getElementById('toast');
  if (t) t.classList.remove('active');
}
function showProgressBar() {
  const b = document.getElementById('progress-bar');
  if (b) b.classList.add('active', 'indet');
}
function hideProgressBar() {
  const b = document.getElementById('progress-bar');
  if (b) b.classList.remove('active', 'indet');
}

const PROGRESS_STAGES_NORMAL = [
  { until: 5,   text: 'Connecting to GBM+…' },
  { until: 15,  text: 'Verificando sesión…' },
  { until: 45,  text: 'Descargando portafolio…' },
  { until: 90,  text: 'Descargando posiciones…' },
  { until: 150, text: 'Descargando historial de operaciones…' },
  { until: Infinity, text: 'Ya casi terminamos…' },
];
const PROGRESS_STAGES_FULL = [
  { until: 5,   text: 'Connecting to GBM+…' },
  { until: 15,  text: 'Verificando sesión…' },
  { until: 45,  text: 'Descargando portafolio…' },
  { until: 90,  text: 'Descargando posiciones…' },
  { until: 240, text: 'Descargando historial completo…' },
  { until: Infinity, text: 'Ya casi terminamos, gracias por la paciencia…' },
];
let _progressStartedAt = null;
let _progressTimer = null;

function showProgressOverlay(opts) {
  const stages = (opts && opts.full) ? PROGRESS_STAGES_FULL : PROGRESS_STAGES_NORMAL;
  setToastTitle((opts && opts.full) ? 'Actualizando tu portafolio' : 'Actualizando tu portafolio');
  showToast(stages[0].text);
  showProgressBar();
  _progressStartedAt = Date.now();
  _progressTimer = setInterval(() => {
    const elapsed = (Date.now() - _progressStartedAt) / 1000;
    const stage = stages.find(s => elapsed < s.until) || stages[stages.length - 1];
    showToast(stage.text);
  }, 500);
}
function hideProgressOverlay() {
  if (_progressTimer) { clearInterval(_progressTimer); _progressTimer = null; }
  _progressStartedAt = null;
  hideProgressBar();
  hideToast();
}

// ============ Network ============
async function postUpdate(mfaCode, opts) {
  const body = {};
  if (mfaCode) body.totp_code = mfaCode;
  if (opts && opts.full) body.full = true;
  const res = await fetch(routes.update, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
    body: JSON.stringify(body),
  });
  let payload = {};
  try { payload = await res.json(); } catch (e) { payload = {}; }
  return { http: res.status, state: payload.status, detail: payload.detail };
}

// ============ Main update flow ============
async function updateData() {
  setUpdateBtn(true, 'Actualizando…');
  let overlayShown = false;
  const overlayDelay = setTimeout(() => {
    showProgressOverlay({ full: false });
    overlayShown = true;
  }, 5500);
  const cleanupOverlay = () => {
    clearTimeout(overlayDelay);
    if (overlayShown) { hideProgressOverlay(); overlayShown = false; }
  };
  try {
    const r = await postUpdate(null);
    if (r.http === 200) {
      clearTimeout(overlayDelay);
      showStatus('ok', '✓ Actualizado — recargando');
      broadcastUpdateComplete();   // tell other tabs to refresh their chip
      setTimeout(() => location.reload(), 800);
      return;
    }
    cleanupOverlay();
    if (r.state === 'mfa_required') { openTotpModal(); return; }
    if (r.state === 'rate_limited') {
      showStatus('err', '⚠ Rate-limited by GBM+ — wait 15–30 min and retry');
      return;
    }
    showStatus('err', '✗ ' + (r.detail || r.state || ('HTTP ' + r.http)));
  } catch (e) {
    cleanupOverlay();
    showStatus('err', '✗ Error de red');
  } finally {
    setUpdateBtn(false);
  }
}

function openTotpModal() {
  const m = document.getElementById('totp-modal');
  if (!m) return;
  // CSS class is `.show` (matches the existing #gbm-app .modal-backdrop.show
  // rule in css/dashboard.css). Was `.open` in v0.14.16-0.14.22 — ported
  // verbatim from TR-owncloud which uses `.open`, but GBM's CSS never had
  // a rule for it so the modal stayed display:none. Fixed v0.14.23.
  m.classList.add('show');
  const errEl = document.getElementById('totp-error');
  if (errEl) errEl.classList.remove('show');
  const inp = document.getElementById('totp-input');
  if (inp) inp.value = '';
  const cb = document.getElementById('totp-full-reload');
  if (cb) cb.checked = false;
  setTimeout(() => { if (inp) inp.focus(); }, 100);
  setUpdateBtn(false);
}
function closeTotpModal() {
  const m = document.getElementById('totp-modal');
  if (m) m.classList.remove('show');
}

async function submitTotp() {
  // Null guards: the modal is normally injected by injectModalsIfMissing(),
  // but if injection failed (CSP, broken DOM) a naked deref here would
  // throw and silently kill the whole flow — same bug class as the
  // settings-btn TypeError that ate v0.14.4-0.14.11.
  const inp = document.getElementById('totp-input');
  const errEl = document.getElementById('totp-error');
  const submitBtn = document.getElementById('totp-submit');
  if (!inp || !errEl || !submitBtn) return;
  const code = inp.value.trim();
  errEl.classList.remove('show');
  if (!/^\d{6}$/.test(code)) {
    errEl.textContent = 'El código debe tener exactamente 6 dígitos.';
    errEl.classList.add('show');
    return;
  }
  submitBtn.disabled = true;
  submitBtn.textContent = 'Verificando…';
  const fullReload = !!document.getElementById('totp-full-reload') &&
                     document.getElementById('totp-full-reload').checked;
  setUpdateBtn(true, fullReload ? 'Re-descargando todo…' : 'Actualizando…');

  closeTotpModal();
  showProgressOverlay({ full: fullReload });

  try {
    const r = await postUpdate(code, { full: fullReload });
    if (r.http === 200) {
      showStatus('ok', '✓ Actualizado — recargando');
      broadcastUpdateComplete();   // tell other tabs to refresh their chip
      setTimeout(() => location.reload(), 800);
      return;
    }
    hideProgressOverlay();
    if (r.state === 'mfa_invalid' || r.state === 'mfa_required') {
      openTotpModal();
      errEl.textContent = 'Código incorrecto o ya expiró. Genera uno nuevo en tu app.';
      errEl.classList.add('show');
      inp.select();
    } else if (r.state === 'auth_failed') {
      openTotpModal();
      errEl.textContent = 'Credenciales inválidas. Reabre ⚙ Configuración y guárdalas otra vez.';
      errEl.classList.add('show');
    } else if (r.state === 'rate_limited') {
      openTotpModal();
      errEl.textContent = '⚠ GBM+ limitó los intentos de login. Espera 15–30 min y reintenta.';
      errEl.classList.add('show');
    } else {
      openTotpModal();
      errEl.textContent = r.detail || ('Error ' + r.http);
      errEl.classList.add('show');
    }
  } catch (e) {
    hideProgressOverlay();
    openTotpModal();
    errEl.textContent = 'Error de red: ' + e.message;
    errEl.classList.add('show');
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = 'Actualizar';
    setUpdateBtn(false);
  }
}

// ============ Staleness chip ============
// Ported from GBM-Dashboard commit 2e01fec (2026-06-02).
// Reads last_update.date via routes.data and injects a colored chip into
// the top-bar .actions on every secondary page. Portfolio (main.php)
// renders its own chip inside the subtitle — this script does NOT run
// there (the page sets data-update-flow-owner="page" and we return
// early), so no conflict.
function stalenessHint(iso) {
  if (!iso) return null;
  const hasTz = /Z|[+-]\d{2}:?\d{2}$/.test(iso.trim());
  const parseable = hasTz ? iso.trim() : iso.trim().replace(' ', 'T');
  const d = new Date(parseable);
  if (isNaN(d.getTime())) return null;
  const mins = Math.floor((Date.now() - d.getTime()) / 60000);
  let label;
  if (mins < 1)       label = 'ahora mismo';
  else if (mins < 60) label = mins + ' min';
  else {
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    label = m === 0 ? h + ' h' : h + ' h ' + m + ' min';
  }
  const severity = mins <= 15 ? 'fresh' : mins <= 60 ? 'warn' : 'stale';
  return { label, severity };
}

// Read last_update.date and re-render the chip in-place. Safe to call
// repeatedly — does nothing if the chip element isn't in the DOM yet.
// On secondary pages the chip carries both absolute + relative time
// ("Actualizado 11:20 · 2h") because there's no separate "Last update"
// text line like Portfolio's subtitle has. (2026-06-03 — Carlos thought
// secondary pages were stale because the chip only showed relative age.)
async function refreshStalenessChip() {
  if (!routes || !routes.data) return;
  const chip = document.getElementById('last-update-age');
  if (!chip) return;
  try {
    const r = await fetch(routes.data.replace('__TYPE__', 'last_update') + '?t=' + Date.now());
    if (!r.ok) return;
    const ts = (await r.text()).trim();
    if (!/\d{4}-\d{2}-\d{2}[ T]\d/.test(ts)) return;
    const s = stalenessHint(ts);
    if (!s) return;
    // Build a short "Actualizado HH:MM · 2h" label. If the update was on
    // a different calendar day, show the date instead of the time.
    const parseable = ts.replace(' ', 'T');
    const d = new Date(parseable);
    const today = new Date();
    const sameDay = d.toDateString() === today.toDateString();
    const abs = sameDay
      ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' +
        d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    chip.textContent = 'Actualizado ' + abs + ' · ' + s.label;
    chip.className = 'staleness-chip show ' + s.severity;
    chip.title = 'Snapshot tomado ' + ts;
  } catch (_) { /* keep prior state on error */ }
}

async function injectStalenessChip() {
  if (!routes || !routes.data) return;
  const actions = document.querySelector('.top-bar .actions');
  if (!actions || document.getElementById('last-update-age')) return;
  const chip = document.createElement('span');
  chip.id = 'last-update-age';
  chip.className = 'staleness-chip';
  const upd = document.getElementById('update-btn');
  if (upd) actions.insertBefore(chip, upd);
  else actions.appendChild(chip);
  await refreshStalenessChip();
  // Poll every minute — keeps "2 min" → "3 min" rolling over,
  // and catches updates triggered from OTHER tabs (where this tab's chip
  // would otherwise stay frozen at its initial value).
  setInterval(refreshStalenessChip, 60_000);
}

// Cross-tab refresh: when an Actualizar completes in another tab,
// BroadcastChannel signals this one to refresh its chip instantly.
// Widely supported (Chrome/Safari/Firefox); silent fallback to 60s poll.
let _trUpdateChannel = null;
try {
  _trUpdateChannel = new BroadcastChannel('gbm-dashboard-update');
  _trUpdateChannel.onmessage = (e) => {
    if (e.data && e.data.type === 'update-complete') {
      refreshStalenessChip();
    }
  };
} catch (_) { /* old browser — fall back to the 60s poll */ }
function broadcastUpdateComplete() {
  if (_trUpdateChannel) {
    try { _trUpdateChannel.postMessage({ type: 'update-complete', t: Date.now() }); } catch (_) {}
  }
}

// ============ Init ============
function init() {
  // Re-entry guard: a second init() (double script load, manual call)
  // would duplicate every addEventListener below — two /update POSTs
  // per click, validation firing twice per keystroke.
  if (window.__updateFlowInitialized) return;
  window.__updateFlowInitialized = true;

  const root = document.getElementById('gbm-app');
  if (!root) return;
  // Portfolio (main.php) ships its own copy of this logic inside dashboard.js
  // for the verbatim-port reasons documented in CLAUDE.md. Skip there to
  // avoid double-binding `#update-btn`.
  if (root.dataset.updateFlowOwner === 'page') return;

  // Need the update route at minimum.
  const updateUrl = root.dataset.routeUpdate;
  if (!updateUrl) return;  // page hasn't opted in (e.g. some future minimal page)

  routes = {
    update: updateUrl,
    index:  root.dataset.routeIndex,
    data:   root.dataset.routeData,
  };

  injectModalsIfMissing();
  injectStalenessChip();

  // Wire the button. Pages may have rendered Actualizar as either an <a>
  // (legacy) or a <button id="update-btn"> (current). Templates have been
  // updated to use the button shape on every page.
  const btn = document.getElementById('update-btn');
  if (btn) btn.addEventListener('click', updateData);

  // Modal interactions.
  const totpInput  = document.getElementById('totp-input');
  const totpCancelar = document.getElementById('totp-cancel');
  const totpActualizar = document.getElementById('totp-submit');
  const totpBack   = document.getElementById('totp-modal');
  const toastX    = document.getElementById('toast-close-btn');
  if (totpInput) {
    totpInput.addEventListener('input', e => {
      e.target.value = e.target.value.replace(/[^0-9]/g, '');
    });
    totpInput.addEventListener('keydown', e => {
      if (e.key === 'Enter') submitTotp();
    });
  }
  if (totpCancelar) totpCancelar.addEventListener('click', closeTotpModal);
  if (totpActualizar) totpActualizar.addEventListener('click', submitTotp);
  if (totpBack) totpBack.addEventListener('click', e => {
    if (e.target === totpBack) closeTotpModal();
  });
  if (toastX) toastX.addEventListener('click', hideToast);

  // ESC closes the MFA modal (matches Portfolio's behaviour).
  // NB: GBM's modal CSS class is `.show` (TR uses `.open`) — keep this
  // check in sync with openTotpModal()/closeTotpModal() above.
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const m = document.getElementById('totp-modal');
    if (m && m.classList.contains('show')) closeTotpModal();
  });
}

window[NS] = { __loaded: true, updateData, openTotpModal };

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
})();
