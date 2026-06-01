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
	const dataUrl = (type) => routes.data.replace('__TYPE__', type);

	// ----------------------------------------------------------------------
	// Format helpers
	// ----------------------------------------------------------------------
	const fmtMoney = (n, opts) => {
		opts = opts || {};
		if (n == null || isNaN(n)) return '—';
		const sign = opts.sign === true;
		const decimals = opts.decimals != null ? opts.decimals : 2;
		const currency = opts.currency === true;
		const abs = Math.abs(n);
		const formatted = abs.toLocaleString('es-MX', {
			minimumFractionDigits: decimals,
			maximumFractionDigits: decimals,
		});
		const signPrefix = n < 0 ? '-' : (sign && n > 0 ? '+' : '');
		const currencyPrefix = currency ? '$' : '';
		return signPrefix + currencyPrefix + formatted;
	};
	const fmtPct = (n) => {
		if (n == null || isNaN(n)) return '—';
		return (n >= 0 ? '+' : '') + (n * 100).toFixed(2) + '%';
	};
	const pnlClass = (n) => (n > 0 ? 'pos' : n < 0 ? 'neg' : 'muted');
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
			const [accountsRes, positionsRes, lastUpdateRes] = await Promise.all([
				fetch(dataUrl('accounts'), opts),
				fetch(dataUrl('positions'), opts),
				fetch(dataUrl('last_update'), opts),
			]);
			const accounts = accountsRes.ok ? await accountsRes.json() : [];
			const positionsByAccount = positionsRes.ok ? await positionsRes.json() : {};
			const lastUpdate = lastUpdateRes.ok ? await lastUpdateRes.text() : '';

			state.accounts = accounts;
			state.positionsByAccount = positionsByAccount;
			state.lastUpdate = lastUpdate.trim();

			state.positionsFlat = [];
			for (const a of accounts) {
				state.positionsFlat.push(...flattenPositions(positionsByAccount[a.legacy_contract_id], a));
			}

			renderAll();
		} catch (err) {
			$('error-box').innerHTML =
				'<div class="error"><b>No se pudieron cargar los datos.</b><br>' +
				'Haz clic en <code>⟳ Actualizar</code> para descargar.<br>' +
				'Detalle: ' + (err.message || err) + '</div>';
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

	// Per-account P&L computed from positions (historical, not intraday).
	// Falls back to accounts.plus_minus when no positions (e.g. Smart Cash
	// at zero, or before first fetch). Ports gbm-dashboard@3eccda2 (v0.5.1).
	function accountPnL(account) {
		const positions = state.positionsFlat.filter(p => p._account_legacy_id === account.legacy_contract_id);
		if (positions.length === 0) {
			return {
				amount:     (account.plus_minus && account.plus_minus.amount) != null ? account.plus_minus.amount : null,
				percentage: account.plus_minus_percentage != null ? account.plus_minus_percentage : null,
			};
		}
		const yieldSum = positions.reduce((s, p) => s + (Number(p.yield_value) || 0), 0);
		const costSum  = positions.reduce((s, p) => s + (Number(p.average_cost) || 0), 0);
		return {
			amount:     yieldSum,
			percentage: costSum > 0 ? yieldSum / costSum : null,
		};
	}

	function aggregates() {
		// Total value: sum each account's value via accountValue() (uses
		// sum(market_value) where available — matches GBM web).
		const totalValue = state.accounts.reduce((s, a) => s + accountValue(a), 0);
		// P&L: historical yield from positions (NOT accounts.plus_minus
		// which is intraday-only and reports 0 when markets are closed).
		const totalPnL = state.positionsFlat.reduce((s, p) => s + (Number(p.yield_value) || 0), 0);
		const totalCost = state.positionsFlat.reduce((s, p) => s + (Number(p.average_cost) || 0), 0);
		const totalPnLPct = totalCost > 0 ? totalPnL / totalCost : 0;
		const numPositions = new Set(state.positionsFlat.map(p => p.issue_id)).size;
		return { totalValue, totalPnL, totalPnLPct, numPositions };
	}

	// ----------------------------------------------------------------------
	// Renderers
	// ----------------------------------------------------------------------
	function renderAll() {
		renderHeader();
		renderCards();
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
		const { totalValue, totalPnL, totalPnLPct, numPositions } = aggregates();
		$('total-value').textContent = fmtMoney(totalValue, { currency: true });
		const pnlEl = $('total-pnl');
		pnlEl.textContent = fmtMoney(totalPnL, { sign: true, currency: true });
		pnlEl.className = 'value ' + pnlClass(totalPnL);
		const pctEl = $('total-pnl-pct');
		pctEl.textContent = fmtPct(totalPnLPct);
		pctEl.className = 'delta ' + pnlClass(totalPnL);
		$('num-positions').textContent = numPositions;
		$('num-accounts').textContent = state.accounts.length;
	}

	function renderAccounts() {
		const grid = $('accounts-grid');
		$('accounts-count').textContent = state.accounts.length;
		grid.innerHTML = state.accounts.map(a => {
			const type = ACCOUNT_TYPES[a.management_type_template] || { label: a.management_type_template, color: 'muted' };
			const value = accountValue(a);
			const { amount: pnl, percentage: pnlPct } = accountPnL(a);
			const posCount = state.positionsFlat.filter(p => p._account_legacy_id === a.legacy_contract_id).length;
			return '<div class="account-chip">' +
				(posCount > 0 ? '<span class="acc-detail-flag">' + posCount + ' pos.</span>' : '') +
				'<div class="acc-type ' + type.color + '">' + type.label + '</div>' +
				'<div class="acc-name">' + (a.name || '—') + '</div>' +
				'<div class="acc-id">' + a.legacy_contract_id + '</div>' +
				'<div class="acc-value">' + fmtMoney(value, { currency: true }) + '</div>' +
				'<div class="acc-pnl ' + pnlClass(pnl) + '">' +
					(pnl != null ? fmtMoney(pnl, { sign: true, currency: true }) : '—') +
					(pnlPct != null ? ' (' + fmtPct(pnlPct) + ')' : '') +
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
			? '<div style="color: var(--muted); font-size: 10px; font-weight: 400;">' + p.account_list + '</div>'
			: '';
		const qtyDecimals = (p.quantity % 1 === 0) ? 0 : 4;
		return '<tr>' +
			'<td class="ticker">' + p.issue_id + subtitle + '</td>' +
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
				? '<span title="' + p._account_list + '">' + p._account_count + ' cuentas</span>' +
				  '<div style="color: var(--muted); font-size: 10px;">' + p._account_list + '</div>'
				: (p._account_list || '—');
			return '<tr>' +
				'<td class="ticker">' + p.issue_id +
					'<div style="color: var(--muted); font-size: 11px; font-weight: 400;">' + (p.issue_name || '') + '</div>' +
				'</td>' +
				'<td><span class="market-pill ' + m.cls + '">' + m.label + '</span></td>' +
				'<td style="color: var(--muted); font-size: 12px;">' + accountCell + '</td>' +
				'<td class="num">' + fmtMoney(p.quantity, { decimals: qtyDecimals }) + '</td>' +
				'<td class="num">' + fmtMoney(p.average_price) + '</td>' +
				'<td class="num">' + fmtMoney(p.last_price) + '</td>' +
				'<td class="num">' + fmtMoney(p.market_value) + '</td>' +
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

	async function triggerUpdate(totpCode) {
		const btn = $('update-btn');
		btn.disabled = true;
		btn.textContent = totpCode ? '⟳ Verificando código...' : '⟳ Conectando...';

		// Defer the heavy overlay: the first probe (no TOTP) might
		// immediately come back with mfa_required, in which case we don't
		// want to flash the overlay before opening the TOTP modal. When a
		// TOTP code IS present we already know the fetch will take minutes,
		// so show the overlay right away. Ports gbm-dashboard@v0.6.1.
		let overlayShown = false;
		let pollTimer = null;
		const startOverlay = () => {
			if (overlayShown) return;
			overlayShown = true;
			showProgressOverlay();
			pollTimer = startProgressPolling();
			btn.textContent = '⟳ Actualizando...';
		};
		const overlayDelay = totpCode != null ? 0 : 700;
		const overlayTimer = setTimeout(startOverlay, overlayDelay);
		const stopOverlay = () => {
			clearTimeout(overlayTimer);
			if (pollTimer) { stopProgressPolling(pollTimer); pollTimer = null; }
			if (overlayShown) { hideProgressOverlay(); overlayShown = false; }
		};

		let res;
		try {
			res = await postJson(routes.update, totpCode ? { totp_code: totpCode } : {});
		} catch (err) {
			stopOverlay();
			btn.disabled = false;
			btn.textContent = '⟳ Actualizar';
			alert('No se pudo conectar al server.\nDetalle: ' + err.message);
			return;
		}

		clearTimeout(overlayTimer);
		if (pollTimer) { stopProgressPolling(pollTimer); pollTimer = null; }

		let payload = {};
		try { payload = await res.json(); } catch (_) {}

		if (res.ok && payload.status === 'ok') {
			closeTotpModal();
			btn.textContent = '⟳ Refrescando vista...';
			await load();
			stopOverlay();
			btn.disabled = false;
			btn.textContent = '⟳ Actualizar';
			return;
		}

		stopOverlay();
		btn.disabled = false;
		btn.textContent = '⟳ Actualizar';

		if (payload.status === 'mfa_required') { openTotpModal(); return; }
		if (payload.status === 'mfa_invalid') { openTotpModal('Código incorrecto o ya expiró. Genera uno nuevo.'); return; }
		if (payload.status === 'auth_failed') {
			closeTotpModal();
			openConfigModal();
			const errEl = $('config-error');
			errEl.textContent = 'Las credenciales son incorrectas o GBM las rechazó.';
			errEl.classList.remove('hidden');
			return;
		}
		if (payload.status === 'config_error') {
			closeTotpModal();
			openConfigModal(true);
			return;
		}
		if (payload.status === 'api_error' || payload.status === 'timeout') {
			closeTotpModal();
			alert('La API de GBM falló: ' + (payload.detail || 'sin detalle'));
			return;
		}
		closeTotpModal();
		alert('Update falló (HTTP ' + res.status + '): ' + (payload.detail || 'sin detalle'));
	}

	function openTotpModal(errorMsg) {
		const modal = $('totp-modal');
		const errEl = $('totp-error');
		const input = $('totp-input');
		if (errorMsg) { errEl.textContent = errorMsg; errEl.classList.remove('hidden'); }
		else { errEl.classList.add('hidden'); }
		input.value = '';
		$('totp-submit').disabled = true;
		modal.classList.add('show');
		setTimeout(() => input.focus(), 100);
	}
	function closeTotpModal() { $('totp-modal').classList.remove('show'); }

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

	// ----------------------------------------------------------------------
	// Progress overlay during /update fetches (ports gbm-dashboard v0.6.2).
	// Friendly Spanish stages that rotate based on elapsed time.
	// ----------------------------------------------------------------------
	const PROGRESS_STAGES = [
		{ until: 3,        text: 'Conectando con GBM…' },
		{ until: 12,       text: 'Descargando tu portafolio…' },
		{ until: 45,       text: 'Descargando posiciones…' },
		{ until: 120,      text: 'Descargando historial de operaciones…' },
		{ until: 180,      text: 'Ya casi terminamos…' },
		{ until: Infinity, text: 'Sigue trabajando, espera un poco más…' },
	];
	let _progressStartedAt = null;

	function showProgressOverlay() {
		$('progress-overlay').classList.add('show');
		$('progress-stage').textContent = PROGRESS_STAGES[0].text;
		_progressStartedAt = Date.now();
	}
	function hideProgressOverlay() {
		$('progress-overlay').classList.remove('show');
		_progressStartedAt = null;
	}
	function startProgressPolling() {
		const updateStage = () => {
			if (_progressStartedAt == null) return;
			const elapsed = (Date.now() - _progressStartedAt) / 1000;
			const stage = PROGRESS_STAGES.find(s => elapsed < s.until)
				|| PROGRESS_STAGES[PROGRESS_STAGES.length - 1];
			const el = $('progress-stage');
			if (el && el.textContent !== stage.text) el.textContent = stage.text;
		};
		updateStage();
		return setInterval(updateStage, 1000);
	}
	function stopProgressPolling(timer) { if (timer != null) clearInterval(timer); }

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
				triggerUpdate();
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

	function onTotpInput(e) {
		const cleaned = e.target.value.replace(/\D/g, '').slice(0, 6);
		e.target.value = cleaned;
		$('totp-submit').disabled = cleaned.length !== 6;
	}

	function submitTotp() {
		const code = $('totp-input').value.trim();
		if (!(code.length === 6 && /^\d+$/.test(code))) return;
		$('totp-submit').disabled = true;
		triggerUpdate(code);
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
		};

		$('update-btn').addEventListener('click', () => triggerUpdate());
		$('settings-btn').addEventListener('click', () => openConfigModal());
		$('search').addEventListener('input', renderTable);
		$('account-filter').addEventListener('change', renderTable);
		$('market-filter').addEventListener('change', renderTable);
		$('pnl-filter').addEventListener('change', renderTable);

		document.querySelectorAll('#positions-table th[data-sort]').forEach(th => {
			th.addEventListener('click', () => setSort(th.dataset.sort));
		});

		// Modal close on backdrop click + cancel buttons.
		$('config-modal').addEventListener('click', (e) => {
			if (e.target.id === 'config-modal') closeConfigModal();
		});
		$('totp-modal').addEventListener('click', (e) => {
			if (e.target.id === 'totp-modal') closeTotpModal();
		});
		$('config-cancel').addEventListener('click', closeConfigModal);
		$('totp-cancel').addEventListener('click', closeTotpModal);

		$('config-email').addEventListener('input', onConfigInput);
		$('config-password').addEventListener('input', onConfigInput);
		$('config-password').addEventListener('keydown', (e) => { if (e.key === 'Enter') submitConfig(); });
		$('config-submit').addEventListener('click', submitConfig);

		$('totp-input').addEventListener('input', onTotpInput);
		$('totp-input').addEventListener('keydown', (e) => { if (e.key === 'Enter') submitTotp(); });
		$('totp-submit').addEventListener('click', submitTotp);

		document.addEventListener('keydown', (e) => {
			if (e.key !== 'Escape') return;
			closeTotpModal();
			closeConfigModal();
		});

		maybeShowConfigOnFirstLoad();
		load();
	});
})();
