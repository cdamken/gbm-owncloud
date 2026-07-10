/* global OC */
/**
 * GBM Portfolio — portfolio page logic.
 *
 * Ported from gbm-dashboard/app/index.html. URLs come from data-route-*
 * attributes on #gbm-app (set by templates/main.php) — that way nothing
 * has to live in an inline <script>, which OC's default CSP would block.
 * POSTs carry the ownCloud CSRF token.
 */
(function () {
	'use strict';

	// ownCloud injects our script in <head>, so it executes BEFORE the DOM is
	// parsed — `document.getElementById('gbm-app')` would be null at top-level.
	// We populate `routes` inside DOMContentLoaded and only ever call functions
	// that touch it after that.
	let routes;
	let usdMxnRate = null;  // pesos per 1 USD, for the Trading USA "(≈ $USD)" hint
	const dataUrl = (type) => routes.data.replace('__TYPE__', type);

	// ----------------------------------------------------------------------
	// Format helpers — single source of truth in js/_shared.js (loaded
	// first by PageController). v0.14.18 dropped six duplicate copies.
	// ----------------------------------------------------------------------
	const fmtMoney = window.fmtMoney;
	const fmtPct   = window.fmtPct;
	const pnlClass = window.pnlClass;
	const esc      = window.esc;
	// Server is UTC; user may be in another TZ. Attach 'Z' if no TZ marker
	// then display in Mexico City time (where the market trades).
	const formatTimestamp = (iso) => {
		if (!iso) return '—';
		const hasTz = /Z|[+-]\d{2}:?\d{2}$/.test(iso.trim());
		const parseable = hasTz ? iso.trim() : iso.trim().replace(' ', 'T') + 'Z';
		const d = new Date(parseable);
		if (isNaN(d.getTime())) return iso;
		return d.toLocaleString('es-MX', {
			timeZone: 'America/Mexico_City',
			year: 'numeric', month: 'short', day: 'numeric',
			hour: '2-digit', minute: '2-digit',
		}) + ' CDMX';
	};
	// Age + severity chip for the "Última actualización" timestamp.
	const stalenessHint = (iso) => {
		if (!iso) return null;
		const hasTz = /Z|[+-]\d{2}:?\d{2}$/.test(iso.trim());
		const parseable = hasTz ? iso.trim() : iso.trim().replace(' ', 'T') + 'Z';
		const d = new Date(parseable);
		if (isNaN(d.getTime())) return null;
		const mins = Math.floor((Date.now() - d.getTime()) / 60000);
		let label;
		if (mins < 1) label = 'ahora';
		else if (mins < 60) label = 'hace ' + mins + ' min';
		else {
			const h = Math.floor(mins / 60);
			const m = mins % 60;
			label = m === 0 ? 'hace ' + h + ' h' : 'hace ' + h + ' h ' + m + ' min';
		}
		const severity = mins <= 15 ? 'fresh' : mins <= 60 ? 'warn' : 'stale';
		return { label, severity, ageMinutes: mins };
	};

	const ACCOUNT_TYPES = {
		trading:     { label: 'Trading MX',     color: 'blue' },
		trading_usa: { label: 'Trading USA',    color: 'purple' },
		smart_cash:  { label: 'Smart Cash',     color: 'amber' },
		wealth:      { label: 'Smart Cash USD', color: 'amber' },
	};
	const MARKETS = {
		mercados_globales_sic:      { label: 'SIC',        cls: 'market-sic' },
		mercado_capitales:          { label: 'BMV',        cls: 'market-bmv' },
		sociedades_inversion_deuda: { label: 'F. Deuda',   cls: 'market-deuda' },
		sociedades_inversion_comun: { label: 'F. Común',   cls: 'market-comun' },
		mercado_extranjero:         { label: 'Extranjero', cls: 'market-extranjero' },
		efectivo:                   { label: 'Efectivo',   cls: 'market-efectivo' },
	};
	const INVEST_SECTIONS = [
		'mercados_globales_sic',
		'mercado_capitales',
		'sociedades_inversion_deuda',
		'sociedades_inversion_comun',
		'mercado_extranjero',
	];

	const state = {
		summary: null,
		accounts: [],
		positionsByAccount: {},
		positionsFlat: [],
		lastUpdate: null,
		sortKey: 'market_value',
		sortDir: 'desc',
	};

	const $ = (id) => document.getElementById(id);

	// ----------------------------------------------------------------------
	// Loader
	// ----------------------------------------------------------------------
	async function load() {
		try {
			const opts = { cache: 'no-store', headers: { Accept: 'application/json' } };
			// last_update is text/plain, JSON files are JSON. Each fetch silently
			// falls back to empty on 404 so a fresh install renders correctly.
			const [summaryRes, accountsRes, positionsRes, fxRes, lastUpdateRes] = await Promise.all([
				fetch(routes.summary, opts),
				fetch(dataUrl('accounts'), opts),
				fetch(dataUrl('positions'), opts),
				fetch(dataUrl('fx'), opts),
				fetch(dataUrl('last_update'), opts),
			]);
			const summary = summaryRes.ok ? await summaryRes.json() : null;
			const accounts = accountsRes.ok ? await accountsRes.json() : [];
			const positionsByAccount = positionsRes.ok ? await positionsRes.json() : {};
			const fx = fxRes.ok ? await fxRes.json() : null;
			usdMxnRate = fx && Number(fx.usdmxn) > 0 ? Number(fx.usdmxn) : null;
			const lastUpdate = lastUpdateRes.ok ? await lastUpdateRes.text() : '';

			state.summary = summary;
			state.accounts = sortAccounts(accounts);
			state.positionsByAccount = positionsByAccount;
			state.lastUpdate = lastUpdate.trim();

			state.positionsFlat = [];
			for (const a of accounts) {
				state.positionsFlat.push(...flattenPositions(positionsByAccount[a.legacy_contract_id], a));
			}

			// If the reconciling summary failed, the KPI cards stay at "—".
			// Surface that as an error so a failed fetch is never mistaken for
			// real zeros — the headline the owner checks first must not lie by
			// omission while the positions table below renders normally.
			if (!summaryRes.ok) {
				$('error-box').innerHTML =
					'<div class="warning"><b>No se pudieron cargar los indicadores del portafolio.</b> ' +
					'Recarga la página o dale <code>🔄 Actualizar</code>.</div>';
			}

			renderAll();
		} catch (err) {
			$('error-box').innerHTML =
				'<div class="error"><b>No se pudieron cargar los datos.</b><br>' +
				'Haz clic en <code>🔄 Actualizar</code> para descargar.<br>' +
				'Detalle: ' + esc(err.message || err) + '</div>';
		}
	}

	function flattenPositions(posData, account) {
		if (!posData) return [];
		const all = [];
		for (const key of INVEST_SECTIONS) {
			const section = posData[key] || [];
			for (const p of section) {
				if (p.issue_id === 'Subtotal') continue;
				all.push(Object.assign({}, p, {
					_market_key: key,
					_account_legacy_id: account ? account.legacy_contract_id : null,
					_account_name: account ? account.name : null,
				}));
			}
		}
		return all;
	}

	// Display order: by GBM's `number` ASC (= EP47NC01 → EP47NC05).
	function sortAccounts(accounts) {
		return [...accounts].sort((a, b) => (a.number || 0) - (b.number || 0));
	}

	// Per-account value: prefer sum(market_value) of positions when available
	// (most accurate, matches what GBM web shows, includes cash/efectivo);
	// fall back to accounts.position.amount when there's no detailed data.
	// Ports gbm-dashboard@386116c (v0.5.2).
	function accountValue(account) {
		const acctPositions = state.positionsByAccount && state.positionsByAccount[account.legacy_contract_id];
		if (acctPositions) {
			let total = 0;
			const sections = [
				'mercados_globales_sic', 'mercado_capitales',
				'sociedades_inversion_deuda', 'sociedades_inversion_comun',
				'mercado_extranjero', 'efectivo',
			];
			for (const sk of sections) {
				const items = acctPositions[sk] || [];
				for (const p of items) {
					if (p.issue_id === 'Subtotal') continue;
					total += Number(p.market_value) || 0;
				}
			}
			if (total !== 0) return total;
		}
		return (account.position && account.position.amount) || 0;
	}

	// ----------------------------------------------------------------------
	// Concentration warning — single-name or top-5 risk.
	// Heuristic: red if >50% / >85% top-5; amber if >30% / >70% top-5.
	// Aggregates by ticker across accounts. Cash (efectivo) is never a
	// *candidate* top holding, but it MUST count in the denominator: the
	// banner says "% del portafolio", so a holding's share is measured against
	// EVERYTHING owned (incl. idle cash like unused Trading USA dollars).
	// Otherwise a small position in a mostly-cash account looks dominant.
	// ----------------------------------------------------------------------
	function computeConcentration() {
		const flat = state.positionsFlat.filter(
			p => p._market_key !== 'efectivo' && p.issue_id !== 'Subtotal'
		);
		if (flat.length === 0) return null;
		const byIssue = {};
		for (const p of flat) {
			const v = Number(p.market_value) || 0;
			if (v <= 0) continue;
			byIssue[p.issue_id] = (byIssue[p.issue_id] || 0) + v;
		}
		const entries = Object.entries(byIssue).sort((a, b) => b[1] - a[1]);
		if (entries.length === 0) return null;
		// Denominator = TOTAL portfolio incl. cash + every account.
		const total = state.accounts.reduce((s, a) => s + accountValue(a), 0);
		if (total <= 0) return null;
		const topTicker = entries[0][0];
		const topShare = entries[0][1] / total;
		const top5Share = entries.slice(0, 5).reduce((s, kv) => s + kv[1], 0) / total;
		let level = 'none';
		if (topShare > 0.50 || top5Share > 0.85) level = 'severe';
		else if (topShare > 0.30 || top5Share > 0.70) level = 'caution';
		return { level, topTicker, topShare, top5Share };
	}
	function renderConcentrationWarning() {
		const c = computeConcentration();
		const box = $('concentration-warning');
		if (!box) return;
		if (!c || c.level === 'none') { box.innerHTML = ''; return; }
		const pct = (n) => (n * 100).toFixed(1) + '%';
		const cls = c.level === 'severe' ? 'warning severe' : 'warning';
		let headline;
		if (c.topShare > 0.30) {
			headline = '<b>Concentración alta:</b> ' + esc(c.topTicker) + ' es ' + pct(c.topShare) + ' del portafolio';
			if (c.top5Share > 0.70) headline += '. Top 5 emisoras = ' + pct(c.top5Share);
			headline += '.';
		} else {
			headline = '<b>Top 5 emisoras concentran ' + pct(c.top5Share) + '</b> del portafolio.';
		}
		const detail = c.level === 'severe'
			? 'Riesgo de exposición single-name elevado. Considera diversificar.'
			: 'Considera revisar si la concentración es intencional.';
		box.innerHTML = '<div class="' + cls + '">' + headline + '<div class="detail">' + detail + '</div></div>';
	}

	// ----------------------------------------------------------------------
	// Renderers
	// ----------------------------------------------------------------------
	function renderAll() {
		renderHeader();
		renderCards();
		renderConcentrationWarning();
		renderAccounts();
		populateAccountFilter();
		renderTopMovers();
		renderTable();
	}

	function populateAccountFilter() {
		const sel = $('account-filter');
		while (sel.children.length > 1) sel.removeChild(sel.lastChild);
		for (const a of state.accounts) {
			const hasPositions = state.positionsFlat.some(p => p._account_legacy_id === a.legacy_contract_id);
			if (!hasPositions) continue;
			const opt = document.createElement('option');
			opt.value = a.legacy_contract_id;
			opt.textContent = (a.name || a.legacy_contract_id) + ' (' + a.legacy_contract_id + ')';
			sel.appendChild(opt);
		}
	}

	function renderHeader() {
		const root = state.accounts.map(a => a.legacy_contract_id)
			.reduce((acc, id) => (acc && acc.length < id.length ? acc : id), null);
		const contractCode = root ? root.slice(0, -2) : '?';
		$('contract-label').textContent = 'Contrato: ' + contractCode;
		$('last-update').textContent = formatTimestamp(state.lastUpdate);
		const stale = stalenessHint(state.lastUpdate);
		const chip = $('last-update-age');
		if (stale && chip) {
			chip.textContent = stale.label;
			chip.className = 'staleness-chip show ' + stale.severity;
			chip.title = stale.severity === 'stale'
				? 'Tu snapshot es viejo. Dale Actualizar para refrescar.'
				: stale.severity === 'warn'
				? 'Tu snapshot tiene más de 15 min.'
				: 'Datos frescos.';
		}
	}

	function renderCards() {
		const s = state.summary;
		if (!s) return;
		$('total-value').textContent = fmtMoney(s.total_value, { currency: true });
		$('investment-cost').textContent = fmtMoney(s.cost_basis, { currency: true });
		const pnlEl = $('total-pnl');
		pnlEl.textContent = fmtMoney(s.unrealized_pl, { sign: true, currency: true });
		pnlEl.className = 'value ' + pnlClass(s.unrealized_pl);
		const pctEl = $('total-pnl-pct');
		pctEl.textContent = fmtPct((Number(s.unrealized_pct) || 0) / 100);
		pctEl.className = 'delta ' + pnlClass(s.unrealized_pl);
		$('available-cash').textContent = fmtMoney(s.cash, { currency: true });
		$('num-positions').textContent = s.positions_count;
		$('num-accounts').textContent = (s.accounts || []).length;
		// XIRR — money-weighted; honest fallback when it can't converge.
		const xEl = $('xirr-value');
		const xDetail = $('xirr-detail');
		if (s.xirr_status === 'ok' && s.xirr != null) {
			xEl.textContent = fmtPct(s.xirr);
			xEl.className = 'value ' + pnlClass(s.xirr);
			if (xDetail) xDetail.textContent = 'personal · money-weighted';
		} else {
			xEl.textContent = '—';
			xEl.className = 'value muted';
			if (xDetail) xDetail.textContent = 'faltan flujos externos';
		}
	}

	function renderAccounts() {
		const s = state.summary;
		const list = (s && s.accounts) || [];
		const grid = $('accounts-grid');
		$('accounts-count').textContent = list.length;
		grid.innerHTML = list.map(a => {
			return '<div class="account-chip">' +
				'<div class="acc-name">' + esc(a.name || '—') + '</div>' +
				'<div class="acc-id">' + esc(a.key || '') + '</div>' +
				'<div class="acc-value">' + fmtMoney(a.value, { currency: true }) + '</div>' +
				'<div class="acc-pnl ' + pnlClass(a.unrealized_pl) + '">' +
					fmtMoney(a.unrealized_pl, { sign: true, currency: true }) +
				'</div>' +
			'</div>';
		}).join('');
	}

	function renderTopMovers() {
		const equity = state.positionsFlat.filter(p =>
			p._market_key === 'mercados_globales_sic' ||
			p._market_key === 'mercado_capitales' ||
			p._market_key === 'mercado_extranjero'
		);

		// Aggregate by ticker so a position held in multiple accounts (e.g.
		// FSHOP 13 in both Personal and Asesor) shows as ONE entry with the
		// combined totals and a recomputed P&L percentage.
		const byIssue = {};
		for (const p of equity) {
			const k = p.issue_id;
			if (!byIssue[k]) {
				byIssue[k] = {
					issue_id: k,
					quantity: 0,
					yield_value: 0,
					market_value: 0,
					average_cost: 0,
					accounts: new Set(),
				};
			}
			const agg = byIssue[k];
			agg.quantity += Number(p.quantity) || 0;
			agg.yield_value += Number(p.yield_value) || 0;
			agg.market_value += Number(p.market_value) || 0;
			agg.average_cost += Number(p.average_cost) || 0;
			if (p._account_name) agg.accounts.add(p._account_name);
		}
		// Recompute % from totals (NOT a naive average of the individual %s —
		// that would be wrong when quantities differ).
		for (const agg of Object.values(byIssue)) {
			agg.historical_variation_percentage =
				agg.average_cost > 0 ? agg.yield_value / agg.average_cost : 0;
			agg.account_list = Array.from(agg.accounts).join(', ');
		}

		const sorted = Object.values(byIssue).sort((a, b) =>
			(b.historical_variation_percentage != null ? b.historical_variation_percentage : -Infinity) -
			(a.historical_variation_percentage != null ? a.historical_variation_percentage : -Infinity)
		);
		const winners = sorted.slice(0, 5);
		const losers = sorted.slice(-5).reverse();
		$('top-winners').innerHTML = winners.map(positionMoverRow).join('') || emptyRow();
		$('top-losers').innerHTML = losers.map(positionMoverRow).join('') || emptyRow();
	}

	function emptyRow() {
		return '<tr><td colspan="4" style="color: var(--muted); text-align: center;">Sin datos</td></tr>';
	}

	function positionMoverRow(p) {
		const pct = p.historical_variation_percentage;
		// When a ticker is held in more than one account, show the list
		// underneath so it's clear the row aggregates them.
		const subtitle = (p.account_list && p.accounts && p.accounts.size > 1)
			? '<div style="color: var(--muted); font-size: 10px; font-weight: 400;">' + esc(p.account_list) + '</div>'
			: '';
		const qtyDecimals = (p.quantity % 1 === 0) ? 0 : 4;
		return '<tr>' +
			'<td class="ticker">' + esc(p.issue_id) + subtitle + '</td>' +
			'<td class="num">' + fmtMoney(p.quantity, { decimals: qtyDecimals }) + '</td>' +
			'<td class="num ' + pnlClass(pct) + '">' + fmtPct(pct) + '</td>' +
			'<td class="num ' + pnlClass(p.yield_value) + '">' + fmtMoney(p.yield_value, { sign: true }) + '</td>' +
		'</tr>';
	}

	function setSort(key) {
		if (state.sortKey === key) {
			state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
		} else {
			state.sortKey = key;
			state.sortDir = 'desc';
		}
		renderTable();
	}

	// Trading USA positions arrive from GBM's API in pesos (no USD field);
	// show the ≈ USD equivalent next to the peso value, the way GBM's own app
	// does. Rate (pesos per USD) comes from fx.json, fetched at sync time.
	function usdHint(p) {
		if (!p || p._market_key !== 'mercado_extranjero' || !usdMxnRate) return '';
		const usd = (Number(p.market_value) || 0) / usdMxnRate;
		return ' <span class="usd-eq" style="color: var(--muted); font-size: 11px;">(≈ $'
			+ usd.toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' USD)</span>';
	}

	function renderTable() {
		const search = $('search').value.toLowerCase();
		const accountFilter = $('account-filter').value;
		const marketFilter = $('market-filter').value;
		const pnlFilter = $('pnl-filter').value;

		// 1. Filter individual position-account entries first. P&L filter is
		// applied AFTER aggregation so a ticker that's + in one account and
		// - in another stays as one row classified by its net P&L.
		const filtered = state.positionsFlat.filter(p => {
			if (search && !p.issue_id.toLowerCase().includes(search) &&
				!(p.issue_name || '').toLowerCase().includes(search)) return false;
			if (accountFilter && p._account_legacy_id !== accountFilter) return false;
			if (marketFilter && p._market_key !== marketFilter) return false;
			return true;
		});

		// 2. Aggregate by issue_id so a ticker held in N accounts shows ONCE.
		const byIssue = {};
		for (const p of filtered) {
			const k = p.issue_id;
			if (!byIssue[k]) {
				byIssue[k] = {
					issue_id: k,
					issue_name: p.issue_name,
					_market_key: p._market_key,
					quantity: 0,
					market_value: 0,
					yield_value: 0,
					average_cost: 0,
					accounts: new Set(),
				};
			}
			const agg = byIssue[k];
			agg.quantity += Number(p.quantity) || 0;
			agg.market_value += Number(p.market_value) || 0;
			agg.yield_value += Number(p.yield_value) || 0;
			agg.average_cost += Number(p.average_cost) || 0;
			if (p._account_name) agg.accounts.add(p._account_name);
		}

		// 3. Recompute derived metrics from totals.
		const totalPortfolioValue = state.positionsFlat.reduce(
			(s, p) => s + (Number(p.market_value) || 0), 0
		);
		for (const agg of Object.values(byIssue)) {
			agg.average_price = agg.quantity > 0 ? agg.average_cost / agg.quantity : 0;
			agg.last_price = agg.quantity > 0 ? agg.market_value / agg.quantity : 0;
			agg.historical_variation_percentage =
				agg.average_cost > 0 ? agg.yield_value / agg.average_cost : 0;
			agg.position_percentage =
				totalPortfolioValue > 0 ? agg.market_value / totalPortfolioValue : 0;
			agg._account_list = Array.from(agg.accounts).sort().join(', ');
			agg._account_count = agg.accounts.size;
		}

		// 4. Apply P&L filter on the aggregated values.
		let rows = Object.values(byIssue).filter(p => {
			if (pnlFilter === 'winners' && !(p.yield_value > 0)) return false;
			if (pnlFilter === 'losers' && !(p.yield_value < 0)) return false;
			return true;
		});

		rows.sort((a, b) => {
			const va = a[state.sortKey] != null ? a[state.sortKey] : 0;
			const vb = b[state.sortKey] != null ? b[state.sortKey] : 0;
			if (typeof va === 'string') {
				return state.sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
			}
			return state.sortDir === 'asc' ? va - vb : vb - va;
		});

		const totalUniqueTickers = new Set(state.positionsFlat.map(p => p.issue_id)).size;
		$('positions-count').textContent = rows.length + ' / ' + totalUniqueTickers + ' tickers';

		$('positions-tbody').innerHTML = rows.map(p => {
			const m = MARKETS[p._market_key] || { label: '?', cls: '' };
			const qtyDecimals = (p.quantity % 1 === 0) ? 0 : 4;
			// Account cell: name if one account, "N cuentas" + detail if more.
			const accountCell = p._account_count > 1
				? '<span title="' + esc(p._account_list) + '">' + p._account_count + ' cuentas</span>' +
				  '<div style="color: var(--muted); font-size: 10px;">' + esc(p._account_list) + '</div>'
				: esc(p._account_list || '—');
			return '<tr>' +
				'<td class="ticker">' + esc(p.issue_id) +
					'<div style="color: var(--muted); font-size: 11px; font-weight: 400;">' + esc(p.issue_name || '') + '</div>' +
				'</td>' +
				'<td><span class="market-pill ' + m.cls + '">' + m.label + '</span></td>' +
				'<td style="color: var(--muted); font-size: 12px;">' + accountCell + '</td>' +
				'<td class="num">' + fmtMoney(p.quantity, { decimals: qtyDecimals }) + '</td>' +
				'<td class="num">' + fmtMoney(p.average_price) + '</td>' +
				'<td class="num">' + fmtMoney(p.last_price) + '</td>' +
				'<td class="num">' + fmtMoney(p.market_value) + usdHint(p) + '</td>' +
				'<td class="num ' + pnlClass(p.yield_value) + '">' + fmtMoney(p.yield_value, { sign: true }) + '</td>' +
				'<td class="num ' + pnlClass(p.historical_variation_percentage) + '">' + fmtPct(p.historical_variation_percentage) + '</td>' +
			'</tr>';
		}).join('');
	}

	// ----------------------------------------------------------------------
	// Update + TOTP + Config modals
	// ----------------------------------------------------------------------
	function postJson(url, body) {
		return fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'requesttoken': OC.requestToken,
			},
			body: JSON.stringify(body || {}),
		});
	}

	// Update flow (triggerUpdate / submitTotp / openTotpModal /
	// closeTotpModal / showProgressOverlay / hideProgressOverlay /
	// startProgressPolling / stopProgressPolling / revalidateTotpSubmit /
	// onTotpInput) lives in js/update_flow.js — loaded on every page by
	// PageController. dashboard.js used to carry its own verbatim copy
	// (~330 duplicate lines); removed in v0.14.16 once update_flow.js
	// became the single owner. Use window.UpdateFlow.updateData() to
	// trigger an update programmatically.

	// ----------------------------------------------------------------------
	// Config modal (GBM-specific — only lives on main.php). The TOTP modal
	// + progress bar + toast are NOT here; they belong to update_flow.js.
	// ----------------------------------------------------------------------
	async function loadConfigStatus() {
		try {
			const res = await fetch(routes.config, { headers: { Accept: 'application/json' } });
			return await res.json();
		} catch (_) { return { configured: false, email: null }; }
	}

	async function maybeShowConfigOnFirstLoad() {
		const s = await loadConfigStatus();
		if (!s.configured) openConfigModal(true);
	}

	function openConfigModal(firstTime) {
		const modal = $('config-modal');
		const errEl = $('config-error');
		errEl.classList.add('hidden');
		loadConfigStatus().then(s => {
			const emailEl = $('config-email');
			const pwEl = $('config-password');
			if (s.email && !firstTime) emailEl.value = s.email;
			pwEl.value = '';
			$('config-submit').disabled = true;
			modal.classList.add('show');
			setTimeout(() => (s.email ? pwEl : emailEl).focus(), 100);
		});
	}
	function closeConfigModal() { $('config-modal').classList.remove('show'); }

	function onConfigInput() {
		const email = $('config-email').value.trim();
		const pw = $('config-password').value;
		$('config-submit').disabled = !(email.includes('@') && pw.length >= 4);
	}

	async function submitConfig() {
		const email = $('config-email').value.trim();
		const password = $('config-password').value;
		const btn = $('config-submit');
		const errEl = $('config-error');
		btn.disabled = true;
		btn.textContent = 'Guardando...';
		try {
			const res = await postJson(routes.config, { email, password });
			const payload = await res.json();
			if (res.ok && payload.status === 'ok') {
				btn.textContent = 'Guardar';
				closeConfigModal();
				// If the user switched to a different GBM account, the
				// page exposes window.onAccountChanged so it can wipe its
				// in-memory portfolio state before the next fetch fills
				// it. Mirrors gbm-dashboard@v0.13.0 _shared.js:892.
				if (payload.account_changed && typeof window.onAccountChanged === 'function') {
					try { await window.onAccountChanged(); } catch (_) {}
				}
				// Update flow now lives in js/update_flow.js (loaded on every
				// page by PageController). Call its public entry point.
				if (window.UpdateFlow && typeof window.UpdateFlow.updateData === 'function') {
					window.UpdateFlow.updateData();
				}
				return;
			}
			errEl.textContent = payload.detail || 'Error guardando credenciales.';
			errEl.classList.remove('hidden');
		} catch (_) {
			errEl.textContent = 'No se pudo conectar al servidor.';
			errEl.classList.remove('hidden');
		}
		btn.textContent = 'Guardar';
		onConfigInput();
	}

	// ----------------------------------------------------------------------
	// Wire-up
	// ----------------------------------------------------------------------
	document.addEventListener('DOMContentLoaded', () => {
		const root = $('gbm-app');
		// Mark body so CSS can dark-ify ownCloud's white wrappers.
		document.body.classList.add('gbm-app-active');

		routes = {
			index:      root.dataset.routeIndex,
			orders:     root.dataset.routeOrders,
			ordersAll:  root.dataset.routeOrdersAll,
			data:       root.dataset.routeData,
			config:     root.dataset.routeConfig,
			update:     root.dataset.routeUpdate,
			summary:    root.dataset.routeSummary,
		};

		// Defensive wiring: `$('settings-btn')` returns null in the
		// post-top-bar layout (the old subtitle settings link was
		// replaced by the "⚙ Configuración" tab in the nav). Before
		// today, `null.addEventListener(...)` was throwing a TypeError
		// that aborted the rest of THIS callback — so every listener
		// below this line (search, filters, modal close, TOTP submit
		// click + Enter) silently failed to attach. Result: clicking
		// "Actualizar" in the TOTP modal did nothing because its
		// listener was never wired up.
		//
		// Fix: a small helper that no-ops on null targets. Every
		// addEventListener below now goes through it.
		const on = (id, evt, fn) => {
			const el = $(id);
			if (el) el.addEventListener(evt, fn);
		};

		// NOTE: the old subtitle `#settings-btn` was removed in v0.11
		// when settings moved into the top-bar as "⚙ Configuración".
		// The `#update-btn`, `#totp-*` and `#toast-close-btn` listeners
		// are now wired by js/update_flow.js (loaded on every page) —
		// removed here in v0.14.16 to eliminate the duplicate update-flow
		// implementation.
		on('search',        'input', renderTable);
		on('account-filter','change', renderTable);
		on('market-filter', 'change', renderTable);
		on('pnl-filter',    'change', renderTable);

		document.querySelectorAll('#positions-table th[data-sort]').forEach(th => {
			th.addEventListener('click', () => setSort(th.dataset.sort));
		});

		// Modal close on backdrop click + cancel buttons. All routed
		// through `on()` so any missing element no-ops cleanly instead
		// of aborting the rest of the wire-up.
		on('config-modal',    'click', (e) => { if (e.target.id === 'config-modal') closeConfigModal(); });
		on('config-cancel',   'click', closeConfigModal);

		on('config-email',    'input',   onConfigInput);
		on('config-password', 'input',   onConfigInput);
		on('config-password', 'keydown', (e) => { if (e.key === 'Enter') submitConfig(); });
		on('config-submit',   'click',   submitConfig);

		document.addEventListener('keydown', (e) => {
			if (e.key !== 'Escape') return;
			closeConfigModal();
		});

		maybeShowConfigOnFirstLoad();
		load();

		// Refresh the staleness chip every 60s so the "hace N min" label
		// rolls forward without a reload (5 min → 6 min → ...). Re-fetches
		// last_update so it ALSO catches updates from other tabs.
		// Ported from gbm-dashboard@03bb089. BroadcastChannel cross-tab
		// signaling skipped for now — the 60s poll covers the common case.
		setInterval(async () => {
			const chip = $('last-update-age');
			if (!chip) return;
			try {
				const r = await fetch(dataUrl('last_update') + '?t=' + Date.now(), { cache: 'no-store' });
				if (!r.ok) return;
				const ts = (await r.text()).trim();
				if (!ts) return;
				state.lastUpdate = ts;
				const stale = stalenessHint(ts);
				if (!stale) return;
				chip.textContent = stale.label;
				chip.className = 'staleness-chip show ' + stale.severity;
				chip.title = formatTimestamp(ts) + '\n' + (
					stale.severity === 'stale' ? 'Tu snapshot es viejo — dale 🔄 Actualizar.'
					: stale.severity === 'warn' ? 'Tu snapshot tiene más de 15 min.'
					: 'Datos frescos.'
				);
			} catch (_) {}
		}, 60_000);
	});
})();
