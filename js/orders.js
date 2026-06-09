/**
 * GBM Portfolio — orders page logic.
 *
 * Ported from gbm-dashboard/app/orders.html; data URLs come from
 * data-route-* attributes on #gbm-app (CSP-safe, no inline script).
 */
(function () {
	'use strict';

	// ownCloud injects our script in <head>, before the body is parsed.
	// Populate routes inside DOMContentLoaded; nothing else runs before that.
	let routes;
	const dataUrl = (type) => routes.data.replace('__TYPE__', type);
	const $ = (id) => document.getElementById(id);

	// Shared formatter — single source of truth in js/_shared.js (v0.14.18).
	const fmtMoney = window.fmtMoney;
	const formatDate = (iso) => {
		if (!iso) return '—';
		const d = new Date(iso);
		if (isNaN(d.getTime())) return iso;
		return d.toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: '2-digit' });
	};
	const formatTime = (iso) => {
		if (!iso) return '—';
		const d = new Date(iso);
		if (isNaN(d.getTime())) return '';
		return d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true });
	};
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
	const MONTH_NAMES = [
		'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
		'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
	];
	const monthKey = (iso) => {
		const d = new Date(iso);
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
	};
	const monthLabel = (key) => {
		const parts = key.split('-');
		return MONTH_NAMES[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
	};

	const MARKETS = { 0: { label: 'BMV', cls: 'market-bmv' }, 2: { label: 'SIC', cls: 'market-sic' } };

	const state = {
		orders: [],
		accounts: [],            // v0.4.3+ multi-account payload.
		positionsByAccount: {},  // v0.5.4 — for synthesizing Trading USA tickers.
		fromDate: null,
		toDate: null,
		sortKey: 'processed_at',
		sortDir: 'desc',
	};

	async function load() {
		try {
			const opts = { cache: 'no-store', headers: { Accept: 'application/json' } };
			// Also fetch positions.json so we can synthesize Trading USA
			// holdings into the Top emisoras section. GBM's USA orders
			// endpoint is unreliable (503 frequently); we derive cost basis
			// from the position snapshot (average_cost / quantity).
			const [ordersRes, positionsRes, lastUpdateRes] = await Promise.all([
				fetch(dataUrl('orders'), opts),
				fetch(dataUrl('positions'), opts),
				fetch(dataUrl('last_update'), opts),
			]);
			const data = ordersRes.ok ? await ordersRes.json() : null;
			const positions = positionsRes.ok ? await positionsRes.json() : {};
			const lastUpdate = lastUpdateRes.ok ? await lastUpdateRes.text() : '';
			if (!data) {
				$('error-box').innerHTML =
					'<div class="error"><b>No hay datos de movimientos todavía.</b><br>' +
					'Ve a <a href="' + routes.index + '" style="color: var(--blue);">Portafolio</a> ' +
					'y dale ⟳ Actualizar — se descargarán las órdenes de los últimos 90 días.</div>';
				return;
			}
			state.orders = data.orders || [];
			state.positionsByAccount = positions || {};
			state.fromDate = data.from_date;
			state.toDate = data.to_date;
			// v0.4.3+ ships an array of accounts; pre-v0.4.3 had a single
			// account_legacy_id / account_name pair. Support both shapes.
			state.accounts = data.accounts || (
				data.account_legacy_id
					? [{ legacy_contract_id: data.account_legacy_id, name: data.account_name }]
					: []
			);

			$('range-label').textContent = 'Rango: ' + state.fromDate + ' → ' + state.toDate;
			const accLabel = state.accounts.length === 0
				? '—'
				: state.accounts.length === 1
					? ((state.accounts[0].name || '—') + ' (' + state.accounts[0].legacy_contract_id + ')')
					: (state.accounts.length + ' cuentas');
			$('account-label').textContent = accLabel;
			$('last-update').textContent = formatTimestamp(lastUpdate.trim());

			populateFilters();
			renderAll();
		} catch (err) {
			$('error-box').innerHTML = '<div class="error"><b>Error cargando datos.</b><br>' + err.message + '</div>';
		}
	}

	function populateFilters() {
		const months = Array.from(new Set(state.orders.map(o => monthKey(o.processed_at)))).sort().reverse();
		const monthSel = $('month-filter');
		for (const m of months) {
			const opt = document.createElement('option');
			opt.value = m; opt.textContent = monthLabel(m);
			monthSel.appendChild(opt);
		}
		const tickers = Array.from(new Set(state.orders.map(o => o.issue_id))).sort();
		const tickerSel = $('ticker-filter');
		for (const t of tickers) {
			const opt = document.createElement('option');
			opt.value = t; opt.textContent = t;
			tickerSel.appendChild(opt);
		}
		// Account filter — only show it if there's more than one trading account.
		const accountsInOrders = Array.from(new Set(state.orders.map(o => o.account_legacy_id || ''))).filter(Boolean);
		if (accountsInOrders.length > 1) {
			$('account-filter-wrap').style.display = '';
			const accSel = $('account-filter');
			for (const id of accountsInOrders) {
				const meta = state.accounts.find(a => a.legacy_contract_id === id);
				const opt = document.createElement('option');
				opt.value = id;
				opt.textContent = (meta ? meta.name : id) + ' (' + id + ')';
				accSel.appendChild(opt);
			}
		}
	}

	function renderAll() {
		renderCards();
		renderMonths();
		renderTickers();
		renderTable();
	}

	function renderCards() {
		const buys = state.orders.filter(o => o.side === 'BUY');
		const sells = state.orders.filter(o => o.side === 'SELL');
		const totalBuy = buys.reduce((s, o) => s + o.amount, 0);
		const totalSell = sells.reduce((s, o) => s + o.amount, 0);
		const totalComm = state.orders.reduce((s, o) => s + o.commission + (o.iva || 0), 0);
		$('num-orders').textContent = state.orders.length;
		$('num-orders-note').textContent = buys.length + ' compras, ' + sells.length + ' ventas';
		$('total-buy').textContent = fmtMoney(totalBuy, { currency: true });
		$('total-sell').textContent = fmtMoney(totalSell, { currency: true });
		$('total-commission').textContent = fmtMoney(totalComm, { currency: true });
	}

	function renderMonths() {
		const byMonth = {};
		for (const o of state.orders) {
			const k = monthKey(o.processed_at);
			if (!byMonth[k]) byMonth[k] = { buys: 0, buy_amt: 0, sells: 0, sell_amt: 0, comm: 0 };
			if (o.side === 'BUY') { byMonth[k].buys++; byMonth[k].buy_amt += o.amount; }
			else                  { byMonth[k].sells++; byMonth[k].sell_amt += o.amount; }
			byMonth[k].comm += o.commission + (o.iva || 0);
		}
		const keys = Object.keys(byMonth).sort().reverse();
		$('months-count').textContent = keys.length;
		$('months-tbody').innerHTML = keys.map(k => {
			const m = byMonth[k];
			return '<tr>' +
				'<td>' + monthLabel(k) + '</td>' +
				'<td class="num">' + m.buys + '</td>' +
				'<td class="num">' + fmtMoney(m.buy_amt, { currency: true }) + '</td>' +
				'<td class="num">' + (m.sells || '—') + '</td>' +
				'<td class="num">' + (m.sells ? fmtMoney(m.sell_amt, { currency: true }) : '—') + '</td>' +
				'<td class="num">' + fmtMoney(m.comm, { currency: true }) + '</td>' +
			'</tr>';
		}).join('') || '<tr><td colspan="6" class="empty">Sin datos</td></tr>';
	}

	function renderTickers() {
		const byTicker = {};
		// From orders: precise count per ticker.
		for (const o of state.orders) {
			if (o.side !== 'BUY') continue;  // ranking by amount invested
			if (!byTicker[o.issue_id]) {
				byTicker[o.issue_id] = { count: 0, qty: 0, amount: 0, source: 'orders' };
			}
			byTicker[o.issue_id].count++;
			byTicker[o.issue_id].qty += o.quantity;
			byTicker[o.issue_id].amount += o.amount;
		}
		// Synthesize from positions: Trading USA fractional shares (DRAM,
		// EWY, …) live in mercado_extranjero. Their orders endpoint
		// (api.trading-usa.gbm.com) returns 503 reliably so they're absent
		// from orders.json. We derive invested amount from average_cost,
		// losing per-order count but gaining the full picture.
		// Ports gbm-dashboard@81fbfae (v0.5.4).
		for (const legacy of Object.keys(state.positionsByAccount)) {
			const acct = state.positionsByAccount[legacy];
			if (!acct) continue;
			for (const p of (acct.mercado_extranjero || [])) {
				if (!p || p.issue_id === 'Subtotal') continue;
				if (byTicker[p.issue_id]) continue;  // already in orders
				byTicker[p.issue_id] = {
					count: null,  // unknown
					qty: Number(p.quantity) || 0,
					amount: Number(p.average_cost) || 0,
					source: 'usa',
				};
			}
		}
		const rows = Object.entries(byTicker).map(([t, v]) => ({
			issue_id: t, count: v.count, qty: v.qty, amount: v.amount,
			avg: v.qty ? v.amount / v.qty : 0,
			source: v.source,
		})).sort((a, b) => b.amount - a.amount).slice(0, 5);
		// Top emisoras top 5 (was 15, then 20 in v0.6.0). Per v0.6.1
		// commit message: "matches the Top ganadores / perdedores tables
		// on the Portafolio page. Less visual noise, same information."

		$('tickers-tbody').innerHTML = rows.map(r => {
			const usaNote = r.source === 'usa'
				? ' <span title="Derivado de la posición actual de Trading USA — el endpoint de órdenes USA no está disponible." style="margin-left:6px; font-size: 9px; color: var(--amber); border: 1px solid var(--amber); padding: 1px 5px; border-radius: 8px;">USA</span>'
				: '';
			const countCell = r.count == null
				? '<span class="muted" title="Las órdenes individuales no están disponibles para Trading USA">—</span>'
				: r.count;
			const qtyDec = (r.qty % 1 === 0) ? 0 : 4;
			return '<tr>' +
				'<td class="ticker">' + r.issue_id + usaNote + '</td>' +
				'<td class="num">' + countCell + '</td>' +
				'<td class="num">' + fmtMoney(r.qty, { decimals: qtyDec }) + '</td>' +
				'<td class="num">' + fmtMoney(r.amount, { currency: true }) + '</td>' +
				'<td class="num">' + fmtMoney(r.avg) + '</td>' +
			'</tr>';
		}).join('') || '<tr><td colspan="5" class="empty">Sin datos</td></tr>';
	}

	function setSort(key) {
		if (state.sortKey === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
		else { state.sortKey = key; state.sortDir = 'desc'; }
		renderTable();
	}

	function renderTable() {
		const search = $('search').value.toLowerCase();
		const sideFilter = $('side-filter').value;
		const monthFilter = $('month-filter').value;
		const tickerFilter = $('ticker-filter').value;
		const accountFilter = $('account-filter').value;

		let rows = state.orders.filter(o => {
			if (search && !o.issue_id.toLowerCase().includes(search)) return false;
			if (sideFilter && o.side !== sideFilter) return false;
			if (monthFilter && monthKey(o.processed_at) !== monthFilter) return false;
			if (tickerFilter && o.issue_id !== tickerFilter) return false;
			if (accountFilter && o.account_legacy_id !== accountFilter) return false;
			return true;
		});
		rows.sort((a, b) => {
			const va = a[state.sortKey]; const vb = b[state.sortKey];
			if (typeof va === 'string' && typeof vb === 'string') {
				return state.sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
			}
			return state.sortDir === 'asc' ? va - vb : vb - va;
		});

		$('orders-count').textContent = rows.length + ' / ' + state.orders.length;
		$('orders-tbody').innerHTML = rows.map(o => {
			const sideClass = o.side === 'BUY' ? 'side-buy' : 'side-sell';
			const sideLabel = o.side === 'BUY' ? 'Compra' : 'Venta';
			const market = MARKETS[o.instrument_type];
			return '<tr>' +
				'<td>' + formatDate(o.processed_at) +
					'<div class="muted" style="font-size: 11px;">' + formatTime(o.processed_at) + '</div>' +
				'</td>' +
				'<td class="ticker">' + o.issue_id +
					(market ? ' <span class="market-pill ' + market.cls + '" style="margin-left: 8px;">' + market.label + '</span>' : '') +
				'</td>' +
				'<td style="color: var(--muted); font-size: 12px;">' + (o.account_name || '—') + '</td>' +
				'<td><span class="side-pill ' + sideClass + '">' + sideLabel + '</span></td>' +
				'<td class="num">' + fmtMoney(o.quantity, { decimals: o.quantity % 1 === 0 ? 0 : 4 }) + '</td>' +
				'<td class="num">' + fmtMoney(o.average_price) + '</td>' +
				'<td class="num">' + fmtMoney(o.amount, { currency: true }) + '</td>' +
				'<td class="num">' + fmtMoney(o.commission) + '</td>' +
				'<td class="sob-id">' + o.sob_id + '</td>' +
			'</tr>';
		}).join('') || '<tr><td colspan="9" class="empty">Sin transacciones que coincidan con los filtros</td></tr>';
	}

	document.addEventListener('DOMContentLoaded', () => {
		const root = $('gbm-app');
		document.body.classList.add('gbm-app-active');
		routes = {
			index:      root.dataset.routeIndex,
			orders:     root.dataset.routeOrders,
			ordersAll:  root.dataset.routeOrdersAll,
			data:       root.dataset.routeData,
			config:     root.dataset.routeConfig,
			update:     root.dataset.routeUpdate,
		};

		$('search').addEventListener('input', renderTable);
		$('side-filter').addEventListener('change', renderTable);
		$('month-filter').addEventListener('change', renderTable);
		$('ticker-filter').addEventListener('change', renderTable);
		$('account-filter').addEventListener('change', renderTable);
		document.querySelectorAll('#orders-table th[data-sort]').forEach(th => {
			th.addEventListener('click', () => setSort(th.dataset.sort));
		});
		load();
	});
})();
