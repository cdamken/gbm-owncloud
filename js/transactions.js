/**
 * GBM Portfolio — Libro Diario (transactions) page logic.
 *
 * Ported from gbm-dashboard/app/transactions.html. Data URLs come from
 * data-route-* attributes on #gbm-app (CSP-safe, no inline script).
 */
(function () {
	'use strict';

	let routes;
	const dataUrl = (type) => routes.data.replace('__TYPE__', type);
	const $ = (id) => document.getElementById(id);

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
		const signPrefix = n < 0 ? '-' : '';
		const currencyPrefix = currency ? '$' : '';
		return signPrefix + currencyPrefix + formatted;
	};
	const formatDate = (iso) => {
		if (!iso) return '—';
		const d = new Date(iso);
		if (isNaN(d.getTime())) return iso;
		return d.toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: '2-digit' });
	};
	const formatTimestamp = (iso) => {
		if (!iso) return '—';
		const d = new Date(iso);
		if (isNaN(d.getTime())) return iso;
		return d.toLocaleString('es-MX', {
			year: 'numeric', month: 'short', day: 'numeric',
			hour: '2-digit', minute: '2-digit',
		});
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

	const CATEGORY_LABELS = {
		buy_stock:       'Compra Acción',
		sell_stock:      'Venta Acción',
		buy_fund:        'Compra Fondo',
		sell_fund:       'Venta Fondo',
		repo_buy:        'Repo (entra)',
		repo_mature:     'Repo (sale)',
		deposit:         'Depósito',
		withdrawal:      'Retiro',
		fx:              'Divisa',
		dividend:        'Dividendo',
		tax_withholding: 'ISR',
		other:           'Otro',
	};
	const categoryLabel = (c) => CATEGORY_LABELS[c] || c || '—';
	const categoryPill = (t) => {
		const c = t.category || 'other';
		return '<span class="cat-pill cat-' + c + '">' + categoryLabel(c) + '</span>';
	};

	const state = {
		rows: [],
		accounts: [],
		fromDate: null,
		toDate: null,
		sortKey: 'process_date',
		sortDir: 'desc',
	};

	async function load() {
		try {
			const opts = { cache: 'no-store', headers: { Accept: 'application/json' } };
			const [dataRes, lastUpdateRes] = await Promise.all([
				fetch(dataUrl('transactions'), opts),
				fetch(dataUrl('last_update'), opts),
			]);
			const data = dataRes.ok ? await dataRes.json() : null;
			const lastUpdate = lastUpdateRes.ok ? await lastUpdateRes.text() : '';
			if (!data) {
				$('error-box').innerHTML =
					'<div class="warning"><b>Aún no hay datos del Libro Diario.</b> ' +
					'Ve a <a href="' + routes.index + '" style="color: var(--blue);">📊 Portafolio</a> ' +
					'y dale <b>⟳ Actualizar</b> para descargar tus movimientos del último año.</div>';
				return;
			}
			state.rows = data.transactions || [];
			state.accounts = data.accounts || [];
			state.fromDate = data.from_date;
			state.toDate = data.to_date;

			$('range-label').textContent = 'Rango: ' + state.fromDate + ' → ' + state.toDate;
			$('last-update').textContent = formatTimestamp(lastUpdate.trim());

			populateFilters();
			render();
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
		const accountsInRows = Array.from(new Set(state.rows.map(d => d.account_legacy_id || ''))).filter(Boolean);
		const accSel = $('account-filter');
		for (const id of accountsInRows) {
			const name = (state.rows.find(d => d.account_legacy_id === id) || {}).account_name || id;
			const opt = document.createElement('option');
			opt.value = id; opt.textContent = name + ' (' + id + ')';
			accSel.appendChild(opt);
		}
	}

	function setSort(key) {
		if (state.sortKey === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
		else { state.sortKey = key; state.sortDir = 'desc'; }
		render();
	}

	function renderCards(rows) {
		const buys        = rows.filter(t => t.is_buy);
		const sells       = rows.filter(t => t.is_sell);
		const deposits    = rows.filter(t => t.category === 'deposit');
		const withdrawals = rows.filter(t => t.category === 'withdrawal');

		const sumAmt = (rs) => rs.reduce((s, t) => s + (t.amount || 0), 0);

		$('total-buys').textContent = '$' + fmtMoney(sumAmt(buys));
		$('buys-detail').textContent = buys.length + ' operación(es)';

		$('total-sells').textContent = '$' + fmtMoney(sumAmt(sells));
		$('sells-detail').textContent = sells.length + ' operación(es)';

		$('total-deposits').textContent = '$' + fmtMoney(sumAmt(deposits));
		$('deposits-detail').textContent = deposits.length + ' depósito(s)';

		$('total-withdrawals').textContent = '$' + fmtMoney(sumAmt(withdrawals));
		$('withdrawals-detail').textContent = withdrawals.length + ' retiro(s)';

		$('total-movements').textContent = rows.length;
		const months = new Set(rows.map(t => monthKey(t.process_date))).size;
		$('movements-detail').textContent = months + ' mes(es) cubierto(s)';
	}

	function render() {
		const search = $('search').value.toLowerCase();
		const catFilter = $('category-filter').value;
		const monthFilter = $('month-filter').value;
		const accountFilter = $('account-filter').value;

		let rows = state.rows.filter(t => {
			const blob = (t.security_id + ' ' + (t.description || '') + ' ' + (t.account_name || '')).toLowerCase();
			if (search && !blob.includes(search)) return false;
			if (catFilter && t.category !== catFilter) return false;
			if (monthFilter && monthKey(t.process_date) !== monthFilter) return false;
			if (accountFilter && t.account_legacy_id !== accountFilter) return false;
			return true;
		});

		renderCards(rows);

		rows.sort((a, b) => {
			const va = a[state.sortKey]; const vb = b[state.sortKey];
			if (typeof va === 'string' && typeof vb === 'string') {
				return state.sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
			}
			return state.sortDir === 'asc' ? (va - vb) : (vb - va);
		});

		$('rows-count').textContent = rows.length + ' / ' + state.rows.length;
		$('tx-tbody').innerHTML = rows.map(t => {
			const desc = t.description || '—';
			const security = (t.security_name && t.security_name.length > 0)
				? '<div class="muted" style="font-size: 11px;">' + t.security_name + '</div>' : '';
			const secId = t.security_id || '—';
			return '<tr>' +
				'<td>' + formatDate(t.process_date) + '</td>' +
				'<td style="color: var(--muted); font-size: 12px;">' + (t.account_name || '—') + '</td>' +
				'<td>' + categoryPill(t) + '</td>' +
				'<td class="ticker">' + secId + security + '</td>' +
				'<td style="font-size: 12px;">' + desc + '</td>' +
				'<td class="num">' + fmtMoney(t.amount, { currency: true }) + '</td>' +
				'<td class="tx-id">' + t.transaction_id + '</td>' +
			'</tr>';
		}).join('') || '<tr><td colspan="7" class="empty">Sin movimientos que coincidan con los filtros</td></tr>';
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

		$('search').addEventListener('input', render);
		$('category-filter').addEventListener('change', render);
		$('month-filter').addEventListener('change', render);
		$('account-filter').addEventListener('change', render);
		document.querySelectorAll('#tx-table th[data-sort]').forEach(th => {
			th.addEventListener('click', () => setSort(th.dataset.sort));
		});

		load();
	});
})();
