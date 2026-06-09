/**
 * GBM Portfolio — Dividendos page logic.
 *
 * Ported from gbm-dashboard/app/dividends.html; data URLs come from
 * data-route-* attributes on #gbm-app (CSP-safe, no inline script).
 */
(function () {
	'use strict';

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

	function classify(d) {
		if (d.is_withholding) return 'tax';
		const desc = (d.description || '').toLowerCase();
		if (desc.includes('abono') || desc.includes('dividendo') || desc.includes('reembolso'))
			return 'payout';
		return 'other';
	}

	const state = {
		rows: [],
		fromDate: null,
		toDate: null,
		sortKey: 'process_date',
		sortDir: 'desc',
	};

	async function load() {
		try {
			const opts = { cache: 'no-store', headers: { Accept: 'application/json' } };
			const [dataRes, lastUpdateRes] = await Promise.all([
				fetch(dataUrl('dividends'), opts),
				fetch(dataUrl('last_update'), opts),
			]);
			const data = dataRes.ok ? await dataRes.json() : null;
			const lastUpdate = lastUpdateRes.ok ? await lastUpdateRes.text() : '';
			if (!data) {
				$('error-box').innerHTML =
					'<div class="warning"><b>Aún no hay datos de dividendos.</b> ' +
					'Ve a <a href="' + routes.index + '" style="color: var(--blue);">📊 Portafolio</a> ' +
					'y dale <b>⟳ Actualizar</b> para descargar tus movimientos del último año.</div>';
				return;
			}
			state.rows = data.dividends || [];
			state.fromDate = data.from_date;
			state.toDate = data.to_date;

			$('range-label').textContent = 'Rango: ' + state.fromDate + ' → ' + state.toDate;
			$('last-update').textContent = formatTimestamp(lastUpdate.trim());

			populateFilters();
			renderCards();
			renderTable();
		} catch (err) {
			$('error-box').innerHTML = '<div class="error"><b>Error cargando datos.</b><br>' + err.message + '</div>';
		}
	}

	function populateFilters() {
		const months = Array.from(new Set(state.rows.map(d => monthKey(d.process_date)))).sort().reverse();
		const monthSel = $('month-filter');
		for (const m of months) {
			const opt = document.createElement('option');
			opt.value = m; opt.textContent = monthLabel(m);
			monthSel.appendChild(opt);
		}
		const tickers = Array.from(new Set(state.rows.map(d => d.security_id))).sort();
		const tickerSel = $('ticker-filter');
		for (const t of tickers) {
			const opt = document.createElement('option');
			opt.value = t; opt.textContent = t;
			tickerSel.appendChild(opt);
		}
		const accountsInRows = Array.from(new Set(state.rows.map(d => d.account_legacy_id || ''))).filter(Boolean);
		if (accountsInRows.length > 1) {
			$('account-filter-wrap').style.display = '';
			const accSel = $('account-filter');
			for (const id of accountsInRows) {
				const name = (state.rows.find(d => d.account_legacy_id === id) || {}).account_name || id;
				const opt = document.createElement('option');
				opt.value = id; opt.textContent = name + ' (' + id + ')';
				accSel.appendChild(opt);
			}
		}
	}

	function renderCards() {
		const payouts = state.rows.filter(d => classify(d) === 'payout');
		const taxes   = state.rows.filter(d => classify(d) === 'tax');
		const gross   = payouts.reduce((s, d) => s + (d.amount || 0), 0);
		const net     = payouts.reduce((s, d) => s + (d.net_amount || 0), 0);
		const tax     = taxes.reduce((s, d) => s + (d.amount || 0), 0);
		const netReceived = net - tax;
		const issuers = new Set(payouts.map(d => d.security_id)).size;

		$('total-net').textContent = '$' + fmtMoney(netReceived);
		$('total-detail').textContent = payouts.length + ' abono(s) · ' + taxes.length + ' retención(es)';
		$('total-gross').textContent = '$' + fmtMoney(gross);
		$('total-tax').textContent = '−$' + fmtMoney(tax);
		$('num-issuers').textContent = issuers;
		$('num-events').textContent = state.rows.length + ' movimiento(s) en total';
	}

	function setSort(key) {
		if (state.sortKey === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
		else { state.sortKey = key; state.sortDir = 'desc'; }
		renderTable();
	}

	function kindPill(d) {
		const k = classify(d);
		if (k === 'payout') return '<span class="kind-pill kind-payout">Abono</span>';
		if (k === 'tax')    return '<span class="kind-pill kind-tax">ISR</span>';
		return '<span class="kind-pill kind-other">Otro</span>';
	}

	function renderTable() {
		const search = $('search').value.toLowerCase();
		const kindFilter = $('kind-filter').value;
		const monthFilter = $('month-filter').value;
		const tickerFilter = $('ticker-filter').value;
		const accountFilter = $('account-filter').value;

		let rows = state.rows.filter(d => {
			const blob = (d.security_id + ' ' + (d.description || '')).toLowerCase();
			if (search && !blob.includes(search)) return false;
			if (kindFilter && classify(d) !== kindFilter) return false;
			if (monthFilter && monthKey(d.process_date) !== monthFilter) return false;
			if (tickerFilter && d.security_id !== tickerFilter) return false;
			if (accountFilter && d.account_legacy_id !== accountFilter) return false;
			return true;
		});

		rows.sort((a, b) => {
			const va = a[state.sortKey]; const vb = b[state.sortKey];
			if (typeof va === 'string' && typeof vb === 'string') {
				return state.sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
			}
			return state.sortDir === 'asc' ? (va - vb) : (vb - va);
		});

		$('rows-count').textContent = rows.length + ' / ' + state.rows.length;
		$('dividends-tbody').innerHTML = rows.map(d => {
			const desc = d.description || '—';
			const security = d.security_name && d.security_name.length > 0
				? '<div class="muted" style="font-size: 11px;">' + d.security_name + '</div>' : '';
			return '<tr>' +
				'<td>' + formatDate(d.process_date) + '</td>' +
				'<td class="ticker">' + d.security_id + security + '</td>' +
				'<td style="font-size: 12px;">' + desc + '</td>' +
				'<td style="color: var(--muted); font-size: 12px;">' + (d.account_name || '—') + '</td>' +
				'<td>' + kindPill(d) + '</td>' +
				'<td class="num">' + fmtMoney(d.amount, { currency: true }) + '</td>' +
				'<td class="num">' + fmtMoney(d.net_amount, { currency: true }) + '</td>' +
				'<td class="tx-id">' + d.transaction_id + '</td>' +
			'</tr>';
		}).join('') || '<tr><td colspan="8" class="empty">Sin movimientos que coincidan con los filtros</td></tr>';
	}

	document.addEventListener('DOMContentLoaded', () => {
		const root = $('gbm-app');
		document.body.classList.add('gbm-app-active');
		routes = {
			index:        root.dataset.routeIndex,
			orders:       root.dataset.routeOrders,
			ordersAll:    root.dataset.routeOrdersAll,
			dividends:    root.dataset.routeDividends,
			transactions: root.dataset.routeTransactions,
			data:         root.dataset.routeData,
			config:       root.dataset.routeConfig,
			update:       root.dataset.routeUpdate,
		};

		$('search').addEventListener('input', renderTable);
		$('kind-filter').addEventListener('change', renderTable);
		$('month-filter').addEventListener('change', renderTable);
		$('ticker-filter').addEventListener('change', renderTable);
		$('account-filter').addEventListener('change', renderTable);
		document.querySelectorAll('#dividends-table th[data-sort]').forEach(th => {
			th.addEventListener('click', () => setSort(th.dataset.sort));
		});

		load();
	});
})();
