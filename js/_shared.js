/**
 * GBM Portfolio — shared chrome injection (top-bar + 7-tab nav).
 *
 * Mirrors gbm-dashboard/app/_shared.js v0.13.0's chrome layer. Loaded by
 * PageController BEFORE each page's per-page JS so #update-btn exists in
 * the DOM by the time the page's DOMContentLoaded handler tries to wire it.
 *
 * The TOTP / config / progress modals already live in templates/main.php
 * and are driven by js/dashboard.js — we don't re-inject them here.
 * The staleness chip element is also already in each template; this file
 * only owns the top-bar (brand + tabs + Actualizar button).
 */
(function () {
	'use strict';

	// ------------------------------------------------------------------
	// Shared formatters (v0.14.18 — Refactor C)
	//
	// fmtMoney, fmtPct, pnlClass used to be redefined inline in every
	// page-level JS (dashboard, analysis, orders, orders_all, dividends,
	// transactions) — six near-identical copies that drifted slightly.
	// Exposed here on `window` so per-page files can grab them with a
	// one-line const alias: `const fmtMoney = window.fmtMoney;`.
	//
	// Mirrors gbm-dashboard/app/_shared.js where fmtMoney is already a
	// top-level function (the upstream isn't wrapped in an IIFE).
	// ------------------------------------------------------------------

	function fmtMoney(n, opts) {
		opts = opts || {};
		if (n == null || isNaN(n)) return '—';
		var sign = opts.sign === true;
		var decimals = opts.decimals != null ? opts.decimals : 2;
		var currency = opts.currency === true;
		var abs = Math.abs(n);
		var formatted = abs.toLocaleString('es-MX', {
			minimumFractionDigits: decimals,
			maximumFractionDigits: decimals,
		});
		var signPrefix = n < 0 ? '-' : (sign && n > 0 ? '+' : '');
		var currencyPrefix = currency ? '$' : '';
		return signPrefix + currencyPrefix + formatted;
	}

	// `n` is a FRACTION, e.g. 0.05 → "+5.00%" (matches upstream
	// gbm-dashboard/app/_shared.js + index.html). Pass already-multiplied
	// values explicitly if you must — divide by 100 before calling.
	function fmtPct(n) {
		if (n == null || isNaN(n)) return '—';
		return (n >= 0 ? '+' : '') + (n * 100).toFixed(2) + '%';
	}

	function pnlClass(n) {
		return n > 0 ? 'pos' : n < 0 ? 'neg' : 'muted';
	}

	// HTML-escape untrusted strings before interpolating them into
	// innerHTML. Mirrors the esc() helper added to gbm-dashboard for the
	// self-XSS fix — wraps broker-supplied string fields (issue_id,
	// account_name, security_name, descriptions, ids) so a crafted value
	// can't inject markup. Numeric fields go through fmtMoney and don't
	// need this.
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	window.fmtMoney = fmtMoney;
	window.fmtPct   = fmtPct;
	window.pnlClass = pnlClass;
	window.esc      = esc;

	// Single source of truth for the tabs — same 7 tabs as upstream
	// (gbm-dashboard/app/_shared.js v0.13.0). "Histórico" (orders_all)
	// is intentionally NOT in the top-bar; it stays reachable via the
	// Movimientos page when relevant.
	const TABS = [
		{ tab: 'portfolio',    routeAttr: 'routeIndex',        label: '📊 Portafolio' },
		{ tab: 'analysis',     routeAttr: 'routeAnalysis',     label: '📈 Análisis' },
		{ tab: 'orders',       routeAttr: 'routeOrders',       label: '📋 Órdenes' },
		{ tab: 'dividends',    routeAttr: 'routeDividends',    label: '💰 Dividendos' },
		{ tab: 'transactions', routeAttr: 'routeTransactions', label: '📒 Libro Diario' },
		{ tab: 'glossary',     routeAttr: 'routeGlossary',     label: '📖 Glosario' },
		{ tab: 'settings',     routeAttr: 'routeSettings',     label: '⚙ Configuración' },
	];

	function _tabFromUrl() {
		// Match the LAST path segment, not indexOf — every ownCloud URL
		// starts with `/index.php/...` which made the old loop match the
		// portfolio tab (slug=`index`) on every page (transactions,
		// analysis, dividends, …) and always render Portafolio active.
		// Regression caught 2026-06-06 by Carlos on the Libro Diario tab.
		const path = location.pathname.replace(/\/+$/, '');
		const last = path.split('/').pop() || '';
		// orders_all groups under "orders" so the tab stays highlighted.
		if (last === 'orders_all') return 'orders';
		for (const t of TABS) {
			if (t.tab === 'portfolio') continue;  // portfolio is the default below
			if (last === t.tab) return t.tab;
		}
		return 'portfolio';
	}

	function injectTopBar() {
		const app = document.getElementById('gbm-app');
		if (!app) return;
		if (app.querySelector('.top-bar')) return;

		const activeTab = app.dataset.tab || _tabFromUrl();

		const bar = document.createElement('div');
		bar.className = 'top-bar';

		const brand = document.createElement('div');
		brand.className = 'brand';
		brand.innerHTML = '<div class="brand-logo">GBM</div>' +
			'<span class="brand-title">GBM Dashboard</span>';

		const nav = document.createElement('nav');
		for (const t of TABS) {
			const href = app.dataset[t.routeAttr] || '#';
			const a = document.createElement('a');
			a.href = href;
			a.textContent = t.label;
			a.dataset.tab = t.tab;
			if (t.tab === activeTab) a.className = 'active';
			nav.appendChild(a);
		}

		const actions = document.createElement('div');
		actions.className = 'actions';
		// Same id (#update-btn) as the old subtitle button so dashboard.js
		// and the per-page JS's existing addEventListener('click', ...)
		// pick up THIS button transparently. The old button is removed
		// from each template; only this one remains.
		const updateBtn = document.createElement('button');
		updateBtn.className = 'update-btn';
		updateBtn.id = 'update-btn';
		updateBtn.type = 'button';
		updateBtn.textContent = '🔄 Actualizar';
		actions.appendChild(updateBtn);

		bar.appendChild(brand);
		bar.appendChild(nav);
		bar.appendChild(actions);

		// Inject as the first child of #gbm-app so it lines up against
		// the top of the app pane (the surrounding body padding stays).
		app.insertBefore(bar, app.firstChild);
	}

	document.addEventListener('DOMContentLoaded', () => {
		document.body.classList.add('gbm-app-active');
		injectTopBar();
	});
})();
