/**
 * GBM Portfolio — Histórico (all orders) page logic.
 *
 * Ported from gbm-dashboard/app/orders_all.html (gbm-dashboard@v0.5).
 * Routes come from data-route-* attributes on #gbm-app (CSP-safe).
 */
(function () {
	'use strict';

	// ownCloud injects our script in <head>, before the body is parsed.
	// Populate routes inside DOMContentLoaded; nothing else runs before that.
	let routes;
	const dataUrl = (type) => routes.data.replace('__TYPE__', type);
	const $ = (id) => document.getElementById(id);

	// ----------------------------------------------------------------------
	// Format helpers
	// ----------------------------------------------------------------------
	const fmtMoney = (n, opts) => {
		opts = opts || {};
		if (n == null || isNaN(n)) return '—';
		const decimals = opts.decimals != null ? opts.decimals : 2;
		const currency = opts.currency === true;
		const abs = Math.abs(n);
		const formatted = abs.toLocaleString('es-MX', {
			minimumFractionDigits: decimals,
			maximumFractionDigits: decimals,
		});
		const sign = n < 0 ? '-' : '';
		return sign + (currency ? '$' : '') + formatted;
	};
	const formatDate = (iso) => {
		if (!iso) return '—';
		const d = new Date(iso);
		if (isNaN(d.getTime())) return iso;
		return d.toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: '2-digit' });
	};
	const formatTime = (iso) => {
		if (!iso) return '';
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
	const monthLabel = (k) => {
		const parts = k.split('-');
		return MONTH_NAMES[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
	};

	const state = {
		orders: [],
		accounts: [],
		fromDate: null,
		toDate: null,
		sortKey: 'processed_at',
		sortDir: 'desc',
	};

	// ----------------------------------------------------------------------
	// Loader
	// ----------------------------------------------------------------------
	async function load() {
		try {
			const opts = { cache: 'no-store', headers: { Accept: 'application/json' } };
			const safeJson = (url) =>
				fetch(url, opts).then(r => (r.ok ? r.json() : null)).catch(() => null);
			const safeText = (url) =>
				fetch(url, opts).then(r => (r.ok ? r.text() : '')).catch(() => '');
			const [data, lastUpdate] = await Promise.all([
				safeJson(dataUrl('orders_all')),
				safeText(dataUrl('last_update')),
			]);
			if (!data) {
				$('error-box').innerHTML =
					'<div class="warning">' +
					'<b>Aún no hay datos del histórico.</b> ' +
					'Ve a <a href="' + routes.index + '" style="color: var(--blue);">📊 Portafolio</a> ' +
					'y dale <b>⟳ Actualizar</b> — se descargan todas las órdenes (cualquier ' +
					'estado) de los últimos 90 días.</div>';
				return;
			}
			state.orders = data.orders || [];
			state.accounts = data.accounts || [];
			state.fromDate = data.from_date;
			state.toDate = data.to_date;

			$('range-label').textContent = 'Rango: ' + state.fromDate + ' → ' + state.toDate;
			$('account-label').textContent =
				state.accounts.length === 1
					? (state.accounts[0].name + ' (' + state.accounts[0].legacy_contract_id + ')')
					: (state.accounts.length + ' cuentas');
			$('last-update').textContent = formatTimestamp(lastUpdate.trim());

			populateFilters();
			renderCards();
			renderTable();
		} catch (err) {
			$('error-box').innerHTML =
				'<div class="error"><b>Error cargando datos.</b><br>' + err.message + '</div>';
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

	function renderCards() {
		const filled = state.orders.filter(o => o.is_filled);
		const cancelled = state.orders.filter(o => o.is_cancelled);
		const other = state.orders.filter(o => !o.is_filled && !o.is_cancelled);
		const sum = (arr) => arr.reduce((s, o) => s + (o.amount || 0), 0);

		$('num-total').textContent = state.orders.length;
		$('num-filled').textContent = filled.length;
		$('filled-amount').textContent = fmtMoney(sum(filled), { currency: true });
		$('num-cancelled').textContent = cancelled.length;
		$('cancelled-amount').textContent = fmtMoney(
			cancelled.reduce((s, o) => s + ((o.original_quantity || 0) * (o.limit_price || 0)), 0),
			{ currency: true }
		);
		$('num-other').textContent = other.length;
		const otherLabels = Array.from(new Set(other.map(o => o.status_label).filter(Boolean)));
		$('other-amount').textContent = otherLabels.length > 0 ? otherLabels.join(', ') : '—';
	}

	function setSort(key) {
		if (state.sortKey === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
		else { state.sortKey = key; state.sortDir = 'desc'; }
		renderTable();
	}

	function statusPillClass(o) {
		if (o.is_filled) return 'status-filled';
		if (o.is_cancelled) return 'status-cancelled';
		return 'status-other';
	}

	function renderTable() {
		const search = $('search').value.toLowerCase();
		const statusFilter = $('status-filter').value;
		const sideFilter = $('side-filter').value;
		const monthFilter = $('month-filter').value;
		const tickerFilter = $('ticker-filter').value;
		const accountFilter = $('account-filter').value;

		let rows = state.orders.filter(o => {
			if (search && !o.issue_id.toLowerCase().includes(search)) return false;
			if (statusFilter === 'filled' && !o.is_filled) return false;
			if (statusFilter === 'cancelled' && !o.is_cancelled) return false;
			if (statusFilter === 'other' && (o.is_filled || o.is_cancelled)) return false;
			if (sideFilter && o.side !== sideFilter) return false;
			if (monthFilter && monthKey(o.processed_at) !== monthFilter) return false;
			if (tickerFilter && o.issue_id !== tickerFilter) return false;
			if (accountFilter && o.account_legacy_id !== accountFilter) return false;
			return true;
		});

		rows.sort((a, b) => {
			const va = a[state.sortKey];
			const vb = b[state.sortKey];
			if (typeof va === 'string' && typeof vb === 'string') {
				return state.sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
			}
			return state.sortDir === 'asc' ? va - vb : vb - va;
		});

		$('orders-count').textContent = rows.length + ' / ' + state.orders.length;

		$('orders-tbody').innerHTML = rows.map(o => {
			const sideClass = o.side === 'BUY' ? 'side-buy' : 'side-sell';
			const sideLabel = o.side === 'BUY' ? 'Compra' : 'Venta';
			const pillCls = statusPillClass(o);
			const statusText = o.is_filled ? 'Llena'
				: o.is_cancelled ? 'Cancelada'
				: (o.status_label || ('Estado ' + o.status));
			const cancelNote = (o.cancel_message && o.is_cancelled)
				? '<div class="muted" style="font-size: 10px;">' + o.cancel_message + '</div>'
				: '';
			const qtyDec = (q) => (q % 1 === 0) ? 0 : 4;
			return '<tr>' +
				'<td>' + formatDate(o.processed_at) +
					'<div class="muted" style="font-size: 11px;">' + formatTime(o.processed_at) + '</div>' +
				'</td>' +
				'<td class="ticker">' + o.issue_id + '</td>' +
				'<td style="color: var(--muted); font-size: 12px;">' + (o.account_name || '—') + '</td>' +
				'<td><span class="side-pill ' + sideClass + '">' + sideLabel + '</span></td>' +
				'<td><span class="status-pill ' + pillCls + '">' + statusText + '</span>' + cancelNote + '</td>' +
				'<td class="num">' + fmtMoney(o.original_quantity, { decimals: qtyDec(o.original_quantity) }) + '</td>' +
				'<td class="num">' + fmtMoney(o.assigned_quantity, { decimals: qtyDec(o.assigned_quantity) }) + '</td>' +
				'<td class="num">' + (o.is_filled ? fmtMoney(o.average_price) : fmtMoney(o.limit_price)) + '</td>' +
				'<td class="num">' + (o.is_filled ? fmtMoney(o.amount, { currency: true }) : '—') + '</td>' +
				'<td class="sob-id">' + o.sob_id + '</td>' +
			'</tr>';
		}).join('') || '<tr><td colspan="10" class="empty">Sin órdenes que coincidan con los filtros</td></tr>';
	}

	// ----------------------------------------------------------------------
	// Wire-up
	// ----------------------------------------------------------------------
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
		$('status-filter').addEventListener('change', renderTable);
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
