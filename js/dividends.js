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

	// Position sections we sum for the cost basis behind "yield on cost".
	const INVEST_SECTIONS = [
		'mercados_globales_sic',
		'mercado_capitales',
		'sociedades_inversion_deuda',
		'sociedades_inversion_comun',
		'mercado_extranjero',
	];
	let _divChart = null;

	const state = {
		rows: [],
		positionsByAccount: {},
		fromDate: null,
		toDate: null,
		sortKey: 'process_date',
		sortDir: 'desc',
	};

	async function load() {
		try {
			const opts = { cache: 'no-store', headers: { Accept: 'application/json' } };
			const [dataRes, positionsRes, lastUpdateRes] = await Promise.all([
				fetch(dataUrl('dividends'), opts),
				fetch(dataUrl('positions'), opts),
				fetch(dataUrl('last_update'), opts),
			]);
			const data = dataRes.ok ? await dataRes.json() : null;
			const positionsByAccount = positionsRes.ok ? await positionsRes.json() : null;
			const lastUpdate = lastUpdateRes.ok ? await lastUpdateRes.text() : '';
			if (!data) {
				$('error-box').innerHTML =
					'<div class="warning"><b>Aún no hay datos de dividendos.</b> ' +
					'Ve a <a href="' + routes.index + '" style="color: var(--blue);">📊 Portafolio</a> ' +
					'y dale <b>⟳ Actualizar</b> para descargar tus movimientos del último año.</div>';
				return;
			}
			state.rows = data.dividends || [];
			state.positionsByAccount = positionsByAccount || {};
			state.fromDate = data.from_date;
			state.toDate = data.to_date;

			$('range-label').textContent = 'Rango: ' + state.fromDate + ' → ' + state.toDate;
			$('last-update').textContent = formatTimestamp(lastUpdate.trim());

			populateFilters();
			renderCards();
			renderDividendForecast();
			renderDividendsChart();
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

	// Forward 12-month dividend projection + yield on cost. Scales observed
	// payouts to a 365-day window (needs ≥90 days), then divides by cost
	// basis (avg_price × qty across all positions). Dividendos en Dividendos.
	function renderDividendForecast() {
		const payouts = (state.rows || []).filter(r => !r.is_withholding);
		const forecastEl = $('div-forecast');
		const detailEl = $('div-forecast-detail');
		const yocEl = $('div-yoc');
		const reset = (msg) => {
			forecastEl.textContent = '—'; forecastEl.className = 'value muted';
			detailEl.textContent = msg; if (yocEl) yocEl.textContent = '—';
		};
		if (!payouts.length) return reset('sin dividendos en historial');
		const dates = payouts.map(r => new Date(r.process_date))
			.filter(d => !isNaN(d.getTime())).sort((a, b) => a - b);
		if (!dates.length) return reset('fechas inválidas');
		const spanDays = Math.max(1, Math.floor((dates[dates.length - 1] - dates[0]) / 86400000));
		if (spanDays < 90) return reset('solo ' + spanDays + 'd de historial (necesita ≥90d)');
		const totalAll = payouts.reduce((s, r) => s + (Number(r.amount) || 0), 0);
		const scaled = spanDays < 365 ? totalAll * (365 / spanDays) : totalAll;
		forecastEl.textContent = fmtMoney(scaled, { currency: true, decimals: 0 });
		forecastEl.className = 'value green';
		detailEl.textContent = 'proyectado de ' + payouts.length + ' pagos en ' + spanDays + 'd';
		if (yocEl) {
			let costBasis = 0;
			const byAccount = state.positionsByAccount || {};
			Object.keys(byAccount).forEach(function (acctId) {
				const acct = byAccount[acctId] || {};
				INVEST_SECTIONS.forEach(function (key) {
					(acct[key] || []).forEach(function (p) {
						if (p.issue_id === 'Subtotal') return;
						costBasis += (Number(p.average_price) || 0) * (Number(p.quantity) || 0);
					});
				});
			});
			yocEl.textContent = costBasis > 0 ? (scaled / costBasis * 100).toFixed(2) + '%' : '—';
		}
	}

	// Monthly dividends bar chart — net payout (kept) vs ISR (withheld),
	// stacked. Ported from gbm-dashboard/app/dividends.html.
	function renderDividendsChart() {
		if (typeof window.Chart !== 'function') return;
		const canvas = $('dividends-chart');
		const emptyEl = $('dividends-empty');
		if (!canvas) return;

		const rows = state.rows || [];
		if (rows.length === 0) {
			if (_divChart) { _divChart.destroy(); _divChart = null; }
			canvas.style.display = 'none';
			if (emptyEl) emptyEl.style.display = 'block';
			return;
		}

		const now = new Date();
		const months = [];
		for (let i = 11; i >= 0; i--) {
			const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
			const key = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
			months.push({ key: key, label: monthLabel(key) });
		}
		const payoutByMonth = {};
		const taxByMonth = {};
		const countByMonth = {};
		for (const row of rows) {
			const k = (row.process_date || '').slice(0, 7);
			if (!k) continue;
			const amt = Number(row.amount) || 0;
			if (row.is_withholding) taxByMonth[k] = (taxByMonth[k] || 0) + amt;
			else payoutByMonth[k] = (payoutByMonth[k] || 0) + amt;
			countByMonth[k] = (countByMonth[k] || 0) + 1;
		}

		const labels = months.map(m => m.label);
		const payouts = months.map(m => payoutByMonth[m.key] || 0);
		const taxes   = months.map(m => taxByMonth[m.key] || 0);

		const totalNet = payouts.reduce((s, v) => s + v, 0);
		const totalTax = taxes.reduce((s, v) => s + v, 0);
		$('div-badge').textContent =
			fmtMoney(totalNet, { currency: true, decimals: 0 }) + ' neto · ' +
			fmtMoney(totalTax, { currency: true, decimals: 0 }) + ' ISR · últimos 12 meses';

		canvas.style.display = '';
		if (emptyEl) emptyEl.style.display = 'none';

		if (_divChart) _divChart.destroy();
		_divChart = new window.Chart(canvas, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [
					{ label: 'Neto recibido', data: payouts,
					  backgroundColor: 'rgba(74, 222, 128, 0.75)', borderColor: '#4ade80', borderWidth: 1, stack: 'div' },
					{ label: 'ISR retenido', data: taxes,
					  backgroundColor: 'rgba(248, 113, 113, 0.75)', borderColor: '#f87171', borderWidth: 1, stack: 'div' },
				],
			},
			options: {
				maintainAspectRatio: false,
				animation: { duration: 600, easing: 'easeOutQuart' },
				scales: {
					x: { stacked: true, ticks: { color: '#7a8599', font: { size: 11 } }, grid: { display: false } },
					y: { stacked: true, ticks: { color: '#7a8599', callback: (v) => fmtMoney(v, { currency: true, decimals: 0 }), font: { size: 11 } }, grid: { color: 'rgba(42, 49, 66, 0.5)' } },
				},
				plugins: {
					legend: { position: 'top', align: 'end',
						labels: { color: '#e8eef5', font: { size: 12, weight: '600' }, usePointStyle: true, pointStyle: 'rect', boxWidth: 10, boxHeight: 10, padding: 12 } },
					tooltip: {
						backgroundColor: '#0f1419', titleColor: '#e8eef5', bodyColor: '#e8eef5',
						borderColor: '#2a3142', borderWidth: 1, padding: 10,
						callbacks: {
							label: (ctx) => ' ' + ctx.dataset.label + ': ' + fmtMoney(ctx.parsed.y, { currency: true, decimals: 2 }),
							footer: (items) => {
								if (!items.length) return '';
								const k = months[items[0].dataIndex].key;
								const count = countByMonth[k];
								return count ? '\n' + count + ' movimiento' + (count === 1 ? '' : 's') : '';
							},
						},
					},
				},
			},
		});
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
