/**
 * GBM Portfolio — Análisis page logic.
 *
 * Verbatim port of gbm-dashboard/app/analysis.html's <script> block.
 * The only patches are the ones forced by the ownCloud port pattern:
 *   - fetch URLs come from data-route-* attrs (no hardcoded /DATA/* paths)
 *   - Chart.js is vendored at js/vendor/chart.umd.min.js (loaded by
 *     PageController before this file)
 *   - shared helpers (fmtMoney, formatTimestamp, stalenessHint, monthLabel)
 *     re-declared locally to match the sibling page JS pattern
 */
(function () {
	'use strict';

	const INVEST_SECTIONS = [
		'mercados_globales_sic',
		'mercado_capitales',
		'sociedades_inversion_deuda',
		'sociedades_inversion_comun',
		'mercado_extranjero',
	];

	let routes;
	const dataUrl = (type) => routes.data.replace('__TYPE__', type);
	const benchmarkUrl = (symbol) => routes.benchmark.replace('__SYMBOL__', encodeURIComponent(symbol));

	const fmtMoney = (n, opts) => {
		opts = opts || {};
		if (n == null || isNaN(n)) return '—';
		const decimals = opts.decimals != null ? opts.decimals : 2;
		const currency = opts.currency === true;
		const sign = opts.sign === true;
		const abs = Math.abs(n);
		const formatted = abs.toLocaleString('es-MX', {
			minimumFractionDigits: decimals,
			maximumFractionDigits: decimals,
		});
		const signPrefix = n < 0 ? '-' : (sign && n > 0 ? '+' : '');
		const currencyPrefix = currency ? '$' : '';
		return signPrefix + currencyPrefix + formatted;
	};
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
	const stalenessHint = (iso) => {
		if (!iso) return null;
		const hasTz = /Z|[+-]\d{2}:?\d{2}$/.test(iso.trim());
		const parseable = hasTz ? iso.trim() : iso.trim().replace(' ', 'T') + 'Z';
		const t = new Date(parseable).getTime();
		if (isNaN(t)) return null;
		const ageMin = (Date.now() - t) / 60000;
		if (ageMin < 15) return { label: 'fresco', severity: 'fresh' };
		if (ageMin < 60 * 24) return { label: Math.floor(ageMin / 60) + 'h', severity: 'warn' };
		return { label: Math.floor(ageMin / (60 * 24)) + 'd', severity: 'stale' };
	};
	const MONTH_NAMES = [
		'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
		'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
	];
	const monthLabel = (key) => {
		const parts = key.split('-');
		return MONTH_NAMES[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
	};

	const state = {
		accounts: [],
		positionsByAccount: {},
		positionsFlat: [],
		dividends: null,
		transactions: null,
		benchmarks: [null, null],
		lastUpdate: null,
	};

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

	async function load() {
		try {
			const opts = { cache: 'no-store', headers: { Accept: 'application/json' } };
			const safeJson = (url) =>
				fetch(url, opts).then(r => (r.ok ? r.json() : null)).catch(() => null);
			const safeText = (url) =>
				fetch(url, opts).then(r => (r.ok ? r.text() : '')).catch(() => '');

			const [accounts, positionsByAccount, dividends, transactions, lastUpdate] = await Promise.all([
				safeJson(dataUrl('accounts')),
				safeJson(dataUrl('positions')),
				safeJson(dataUrl('dividends')),
				safeJson(dataUrl('transactions')),
				safeText(dataUrl('last_update')),
			]);

			state.dividends = dividends;
			state.transactions = transactions;
			state.lastUpdate = (lastUpdate || '').trim();

			// Benchmarks: fetched lazily via /benchmark/{symbol} (24h cache).
			// Errors don't block the page — the overlay just doesn't render.
			state.benchmarks = await Promise.all([
				safeJson(benchmarkUrl('NAFTRACISHRS.MX')),
				safeJson(benchmarkUrl('SPY')),
			]);

			if (!accounts) {
				document.getElementById('error-box').innerHTML =
					'<div class="warning">' +
					'<b>Aún no hay datos descargados.</b> ' +
					'Dale <b>⟳ Actualizar</b> arriba para autenticar con GBM y bajar tu portafolio.' +
					'</div>';
				document.getElementById('allocation-empty').style.display = 'flex';
				return;
			}

			state.accounts = accounts;
			state.positionsByAccount = positionsByAccount || {};
			state.positionsFlat = [];
			for (const a of accounts) {
				const flat = flattenPositions(positionsByAccount[a.legacy_contract_id], a);
				state.positionsFlat.push.apply(state.positionsFlat, flat);
			}

			renderHeader();
			renderAllocationChart();
			renderDividendStats();
			renderDividendsChart();
			renderNetWorthChart();
		} catch (err) {
			document.getElementById('error-box').innerHTML =
				'<div class="error"><b>No se pudieron cargar los datos.</b><br>' +
				'Detalle: ' + (err && err.message ? err.message : String(err)) + '</div>';
		}
	}

	function renderHeader() {
		document.getElementById('last-update').textContent = formatTimestamp(state.lastUpdate);
		const stale = stalenessHint(state.lastUpdate);
		const chip = document.getElementById('last-update-age');
		if (stale && chip) {
			chip.textContent = stale.label;
			chip.className = 'staleness-chip show ' + stale.severity;
			const hint = stale.severity === 'stale'
				? 'Tu snapshot es viejo — dale ⟳ Actualizar.'
				: stale.severity === 'warn'
				? 'Tu snapshot tiene más de 15 min.'
				: 'Datos frescos.';
			chip.title = formatTimestamp(state.lastUpdate) + '\n' + hint;
		}
	}

	// ----------------------------------------------------------------------
	// Allocation ring chart — composition by GBM market bucket.
	// ----------------------------------------------------------------------
	let _allocChart = null;
	function renderAllocationChart() {
		if (typeof window.Chart !== 'function') return;
		const canvas = document.getElementById('allocation-chart');
		const emptyEl = document.getElementById('allocation-empty');
		if (!canvas) return;

		const buckets = {};
		for (const p of state.positionsFlat) {
			if (p.issue_id === 'Subtotal') continue;
			const v = Number(p.market_value) || 0;
			if (v <= 0) continue;
			const k = p._market_key || 'otro';
			buckets[k] = (buckets[k] || 0) + v;
		}

		const ORDER = [
			{ key: 'mercado_capitales',           label: 'BMV',         color: '#60a5fa' },
			{ key: 'mercados_globales_sic',       label: 'SIC',         color: '#c084fc' },
			{ key: 'mercado_extranjero',          label: 'Extranjero',  color: '#4ade80' },
			{ key: 'sociedades_inversion_comun',  label: 'F. Común',    color: '#fbbf24' },
			{ key: 'sociedades_inversion_deuda',  label: 'F. Deuda',    color: '#f59e0b' },
			{ key: 'efectivo',                    label: 'Efectivo',    color: '#7a8599' },
		];
		const labels = [];
		const data = [];
		const colors = [];
		for (const slot of ORDER) {
			const v = buckets[slot.key];
			if (!v) continue;
			labels.push(slot.label);
			data.push(v);
			colors.push(slot.color);
		}
		for (const k of Object.keys(buckets)) {
			if (ORDER.some(s => s.key === k)) continue;
			labels.push(k);
			data.push(buckets[k]);
			colors.push('#6b7280');
		}

		if (data.length === 0) {
			if (_allocChart) { _allocChart.destroy(); _allocChart = null; }
			canvas.style.display = 'none';
			emptyEl.style.display = 'flex';
			return;
		}
		canvas.style.display = '';
		emptyEl.style.display = 'none';

		const total = data.reduce((s, v) => s + v, 0);
		document.getElementById('alloc-badge').textContent =
			data.length + ' mercados · ' + fmtMoney(total, { currency: true, decimals: 0 });

		if (_allocChart) _allocChart.destroy();
		_allocChart = new window.Chart(canvas, {
			type: 'doughnut',
			data: {
				labels,
				datasets: [{
					data,
					backgroundColor: colors,
					borderColor: '#1a1f2e',
					borderWidth: 3,
					hoverOffset: 12,
					hoverBorderWidth: 3,
					spacing: 2,
				}],
			},
			options: {
				maintainAspectRatio: false,
				cutout: '68%',
				animation: { duration: 600, easing: 'easeOutQuart', animateRotate: true },
				plugins: {
					legend: {
						position: 'right',
						labels: {
							color: '#e8eef5',
							font: { size: 13, weight: '600' },
							padding: 16,
							usePointStyle: true,
							pointStyle: 'circle',
							boxWidth: 12,
							boxHeight: 12,
						},
					},
					tooltip: {
						backgroundColor: '#0f1419',
						titleColor: '#e8eef5',
						bodyColor: '#e8eef5',
						borderColor: '#2a3142',
						borderWidth: 1,
						padding: 10,
						callbacks: {
							label: (ctx) => {
								const t = ctx.dataset.data.reduce((s, v) => s + v, 0);
								const pct = t > 0 ? (ctx.parsed / t * 100) : 0;
								return ' ' + ctx.label + ': ' + fmtMoney(ctx.parsed, { currency: true, decimals: 0 }) +
									'  (' + pct.toFixed(1) + '%)';
							},
						},
					},
				},
			},
		});
	}

	// ----------------------------------------------------------------------
	// Dividend stats: received (12m), ISR retenido (12m), forward projection.
	// Forward projection scales the observed payouts to a full 365-day window.
	// Requires ≥90 days of history to avoid noise.
	// ----------------------------------------------------------------------
	function renderDividendStats() {
		const rows = (state.dividends && state.dividends.dividends) || [];
		const payouts = rows.filter(r => !r.is_withholding);
		const taxes = rows.filter(r => r.is_withholding);

		const now = new Date();
		const cutoff = new Date(now);
		cutoff.setDate(cutoff.getDate() - 365);
		const inWindow = (r) => {
			const d = new Date(r.process_date || '');
			return !isNaN(d.getTime()) && d >= cutoff;
		};

		const totalNet = payouts.filter(inWindow)
			.reduce((s, r) => s + (Number(r.amount) || 0), 0);
		const totalTax = taxes.filter(inWindow)
			.reduce((s, r) => s + (Number(r.amount) || 0), 0);

		document.getElementById('div-received').textContent =
			fmtMoney(totalNet, { currency: true, decimals: 2 });
		document.getElementById('div-received-detail').textContent =
			payouts.filter(inWindow).length + ' pagos';
		document.getElementById('div-tax').textContent =
			fmtMoney(totalTax, { currency: true, decimals: 2 });
		document.getElementById('div-tax-detail').textContent =
			taxes.filter(inWindow).length + ' retenciones';

		const forecastEl = document.getElementById('div-forecast');
		const forecastDetailEl = document.getElementById('div-forecast-detail');
		if (payouts.length === 0) {
			forecastEl.textContent = '—';
			forecastEl.className = 'stat-value muted';
			forecastDetailEl.textContent = 'sin dividendos en historial';
			return;
		}
		const dates = payouts
			.map(r => new Date(r.process_date))
			.filter(d => !isNaN(d.getTime()))
			.sort((a, b) => a - b);
		if (dates.length === 0) {
			forecastEl.textContent = '—';
			forecastEl.className = 'stat-value muted';
			forecastDetailEl.textContent = 'fechas inválidas';
			return;
		}
		const first = dates[0];
		const last = dates[dates.length - 1];
		const spanDays = Math.max(1, Math.floor((last - first) / (1000 * 60 * 60 * 24)));
		if (spanDays < 90) {
			forecastEl.textContent = '—';
			forecastEl.className = 'stat-value muted';
			forecastDetailEl.textContent = 'solo ' + spanDays + 'd de historial (necesita ≥90d)';
			return;
		}
		const totalAll = payouts.reduce((s, r) => s + (Number(r.amount) || 0), 0);
		const scaled = spanDays < 365 ? totalAll * (365 / spanDays) : totalAll;
		forecastEl.textContent = fmtMoney(scaled, { currency: true, decimals: 0 });
		forecastEl.className = 'stat-value green';
		forecastDetailEl.textContent =
			'proyectado de ' + payouts.length + ' pagos en ' + spanDays + 'd';
	}

	// ----------------------------------------------------------------------
	// Monthly dividends bar chart — net payout (kept) vs ISR (withheld).
	// Stacked so total bar = gross dividend; the red ISR portion is visible.
	// ----------------------------------------------------------------------
	let _divChart = null;
	function renderDividendsChart() {
		if (typeof window.Chart !== 'function') return;
		const canvas = document.getElementById('dividends-chart');
		const emptyEl = document.getElementById('dividends-empty');
		if (!canvas) return;

		const rows = (state.dividends && state.dividends.dividends) || [];
		if (rows.length === 0) {
			if (_divChart) { _divChart.destroy(); _divChart = null; }
			canvas.style.display = 'none';
			emptyEl.style.display = 'flex';
			return;
		}

		const now = new Date();
		const months = [];
		for (let i = 11; i >= 0; i--) {
			const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
			const key = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
			months.push({ key, label: monthLabel(key) });
		}
		const payoutByMonth = {};
		const taxByMonth = {};
		const countByMonth = {};
		for (const row of rows) {
			const k = (row.process_date || '').slice(0, 7);
			if (!k) continue;
			const amt = Number(row.amount) || 0;
			if (row.is_withholding) {
				taxByMonth[k] = (taxByMonth[k] || 0) + amt;
			} else {
				payoutByMonth[k] = (payoutByMonth[k] || 0) + amt;
			}
			countByMonth[k] = (countByMonth[k] || 0) + 1;
		}

		const labels = months.map(m => m.label);
		const payouts = months.map(m => payoutByMonth[m.key] || 0);
		const taxes   = months.map(m => taxByMonth[m.key] || 0);

		const totalNet = payouts.reduce((s, v) => s + v, 0);
		const totalTax = taxes.reduce((s, v) => s + v, 0);
		document.getElementById('div-badge').textContent =
			fmtMoney(totalNet, { currency: true, decimals: 0 }) + ' neto · ' +
			fmtMoney(totalTax, { currency: true, decimals: 0 }) + ' ISR · últimos 12 meses';

		canvas.style.display = '';
		emptyEl.style.display = 'none';

		if (_divChart) _divChart.destroy();
		_divChart = new window.Chart(canvas, {
			type: 'bar',
			data: {
				labels,
				datasets: [
					{
						label: 'Neto recibido',
						data: payouts,
						backgroundColor: 'rgba(74, 222, 128, 0.75)',
						borderColor: '#4ade80',
						borderWidth: 1,
						stack: 'div',
					},
					{
						label: 'ISR retenido',
						data: taxes,
						backgroundColor: 'rgba(248, 113, 113, 0.75)',
						borderColor: '#f87171',
						borderWidth: 1,
						stack: 'div',
					},
				],
			},
			options: {
				maintainAspectRatio: false,
				animation: { duration: 600, easing: 'easeOutQuart' },
				scales: {
					x: {
						stacked: true,
						ticks: { color: '#7a8599', font: { size: 11 } },
						grid: { display: false },
					},
					y: {
						stacked: true,
						ticks: {
							color: '#7a8599',
							callback: (v) => fmtMoney(v, { currency: true, decimals: 0 }),
							font: { size: 11 },
						},
						grid: { color: 'rgba(42, 49, 66, 0.5)' },
					},
				},
				plugins: {
					legend: {
						position: 'top',
						align: 'end',
						labels: {
							color: '#e8eef5',
							font: { size: 12, weight: '600' },
							usePointStyle: true,
							pointStyle: 'rect',
							boxWidth: 10,
							boxHeight: 10,
							padding: 12,
						},
					},
					tooltip: {
						backgroundColor: '#0f1419',
						titleColor: '#e8eef5',
						bodyColor: '#e8eef5',
						borderColor: '#2a3142',
						borderWidth: 1,
						padding: 10,
						callbacks: {
							label: (ctx) => ' ' + ctx.dataset.label + ': ' +
								fmtMoney(ctx.parsed.y, { currency: true, decimals: 2 }),
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

	// ----------------------------------------------------------------------
	// Net worth (cost-basis) trajectory line chart with benchmark overlays.
	// Reconstructs daily "capital committed" from transactions: each buy
	// adds to running total, each sell subtracts. NOT actual market value.
	// Benchmark replay shows "what if you bought NAFTRAC/SPY at the same
	// monthly cadence" — apples-to-apples with the cost-basis line.
	// ----------------------------------------------------------------------
	let _histChart = null;
	let _histRange = 'ALL';

	function _replayBenchmark(bench, dailyMap) {
		if (!bench || !bench.history || bench.history.length === 0) return null;
		if (!dailyMap || dailyMap.size === 0) return null;
		const benchByMonth = {};
		for (const h of bench.history) benchByMonth[h.date.slice(0, 7)] = h.close;

		const dates = [...dailyMap.keys()].sort();
		const monthlyDelta = {};
		let prevValue = null;
		for (const d of dates) {
			const m = d.slice(0, 7);
			const v = dailyMap.get(d);
			if (prevValue == null) {
				monthlyDelta[m] = v;
			} else {
				monthlyDelta[m] = (monthlyDelta[m] || 0) + (v - prevValue);
			}
			prevValue = v;
		}

		const monthsList = Object.keys(monthlyDelta).sort();
		let units = 0;
		const out = {};
		for (const m of monthsList) {
			const close = benchByMonth[m];
			if (!close || close <= 0) continue;
			const delta = monthlyDelta[m];
			if (delta !== 0) units += delta / close;
			out[m + '-01'] = +(units * close).toFixed(2);
		}
		return out;
	}

	function renderNetWorthChart() {
		if (typeof window.Chart !== 'function') return;
		const canvas = document.getElementById('history-chart');
		const emptyEl = document.getElementById('history-empty');
		if (!canvas) return;

		const rows = (state.transactions && state.transactions.transactions) || [];
		if (rows.length === 0) {
			if (_histChart) { _histChart.destroy(); _histChart = null; }
			canvas.style.display = 'none';
			emptyEl.style.display = 'flex';
			return;
		}

		const sorted = [...rows].sort(
			(a, b) => (a.process_date || '').localeCompare(b.process_date || '')
		);
		let running = 0;
		const dailyMap = new Map();
		for (const t of sorted) {
			const date = (t.process_date || '').slice(0, 10);
			if (!date) continue;
			const amt = Math.abs(Number(t.amount) || 0);
			if (t.is_buy)       running += amt;
			else if (t.is_sell) running -= amt;
			else continue;
			dailyMap.set(date, Math.round(running * 100) / 100);
		}

		if (dailyMap.size === 0) {
			if (_histChart) { _histChart.destroy(); _histChart = null; }
			canvas.style.display = 'none';
			emptyEl.style.display = 'flex';
			return;
		}

		const today = new Date().toISOString().slice(0, 10);
		const datesSorted = [...dailyMap.keys()].sort();
		const lastDate = datesSorted[datesSorted.length - 1];
		if (lastDate !== today) {
			dailyMap.set(today, dailyMap.get(lastDate));
			datesSorted.push(today);
		}

		const rangeDays = { '1M': 30, '3M': 90, '6M': 180, '1Y': 365 }[_histRange];
		let filteredDates = datesSorted;
		if (rangeDays) {
			const cutoff = new Date();
			cutoff.setDate(cutoff.getDate() - rangeDays);
			const cutoffStr = cutoff.toISOString().slice(0, 10);
			filteredDates = datesSorted.filter(d => d >= cutoffStr);
			if (filteredDates.length === 0) filteredDates = [datesSorted[datesSorted.length - 1]];
		}

		const labels = filteredDates.map(d => d);
		const values = filteredDates.map(d => dailyMap.get(d));

		const minV = Math.min.apply(null, values);
		const maxV = Math.max.apply(null, values);
		const fromDate = filteredDates[0];
		const toDate = filteredDates[filteredDates.length - 1];
		document.getElementById('hist-badge').textContent =
			fromDate + ' → ' + toDate + ' · rango ' +
			fmtMoney(minV, { currency: true, decimals: 0 }) + ' a ' +
			fmtMoney(maxV, { currency: true, decimals: 0 });

		canvas.style.display = '';
		emptyEl.style.display = 'none';

		const benchNAFTRAC = state.benchmarks[0];
		const benchSPY = state.benchmarks[1];
		const naftracMap = _replayBenchmark(benchNAFTRAC, dailyMap);
		const spyMap     = _replayBenchmark(benchSPY,     dailyMap);
		const alignBench = (m) => {
			if (!m) return null;
			return filteredDates.map(d => {
				const key = d.slice(0, 7) + '-01';
				return key in m ? m[key] : null;
			});
		};
		const naftracValues = alignBench(naftracMap);
		const spyValues = alignBench(spyMap);

		const datasets = [
			{
				label: 'Capital invertido (cost basis)',
				data: values,
				borderColor: '#60a5fa',
				backgroundColor: 'rgba(96, 165, 250, 0.10)',
				borderWidth: 2,
				fill: true,
				tension: 0.15,
				pointRadius: 0,
				pointHoverRadius: 5,
				pointHoverBackgroundColor: '#60a5fa',
				pointHoverBorderColor: '#0f1419',
				pointHoverBorderWidth: 2,
			},
		];
		if (naftracValues && naftracValues.some(v => v != null)) {
			datasets.push({
				label: 'Si compraras NAFTRAC en su lugar',
				data: naftracValues,
				borderColor: '#fbbf24',
				backgroundColor: 'transparent',
				borderWidth: 2,
				borderDash: [6, 4],
				fill: false,
				tension: 0.15,
				pointRadius: 0,
				pointHoverRadius: 5,
				pointHoverBackgroundColor: '#fbbf24',
				pointHoverBorderColor: '#0f1419',
				pointHoverBorderWidth: 2,
				spanGaps: true,
			});
		}
		if (spyValues && spyValues.some(v => v != null)) {
			datasets.push({
				label: 'Si compraras SPY en su lugar',
				data: spyValues,
				borderColor: '#4ade80',
				backgroundColor: 'transparent',
				borderWidth: 2,
				borderDash: [2, 4],
				fill: false,
				tension: 0.15,
				pointRadius: 0,
				pointHoverRadius: 5,
				pointHoverBackgroundColor: '#4ade80',
				pointHoverBorderColor: '#0f1419',
				pointHoverBorderWidth: 2,
				spanGaps: true,
			});
		}

		if (_histChart) _histChart.destroy();
		_histChart = new window.Chart(canvas, {
			type: 'line',
			data: { labels, datasets },
			options: {
				maintainAspectRatio: false,
				animation: { duration: 600, easing: 'easeOutQuart' },
				interaction: { mode: 'index', intersect: false },
				scales: {
					x: {
						ticks: {
							color: '#7a8599',
							font: { size: 11 },
							maxRotation: 0,
							autoSkip: true,
							maxTicksLimit: 8,
							callback: function (value) {
								const label = this.getLabelForValue(value);
								const d = new Date(label);
								if (isNaN(d)) return label;
								return d.toLocaleDateString('es-MX', { month: 'short', year: '2-digit' });
							},
						},
						grid: { display: false },
					},
					y: {
						ticks: {
							color: '#7a8599',
							callback: (v) => fmtMoney(v, { currency: true, decimals: 0 }),
							font: { size: 11 },
						},
						grid: { color: 'rgba(42, 49, 66, 0.5)' },
					},
				},
				plugins: {
					legend: {
						display: datasets.length > 1,
						position: 'top',
						align: 'end',
						labels: {
							color: '#e8eef5',
							font: { size: 11, weight: '600' },
							usePointStyle: true,
							pointStyle: 'line',
							boxWidth: 24,
							boxHeight: 2,
							padding: 12,
						},
					},
					tooltip: {
						backgroundColor: '#0f1419',
						titleColor: '#e8eef5',
						bodyColor: '#e8eef5',
						borderColor: '#2a3142',
						borderWidth: 1,
						padding: 10,
						callbacks: {
							title: (items) => {
								if (!items.length) return '';
								const d = new Date(items[0].label);
								return isNaN(d)
									? items[0].label
									: d.toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
							},
							label: (ctx) => ' ' + fmtMoney(ctx.parsed.y, { currency: true, decimals: 2 }),
						},
					},
				},
			},
		});
	}

	// ---------- Bootstrap ----------
	document.addEventListener('DOMContentLoaded', () => {
		document.body.classList.add('gbm-app-active');
		const app = document.getElementById('gbm-app');
		if (!app) return;
		routes = {
			data:      app.dataset.routeData,
			update:    app.dataset.routeUpdate,
			benchmark: app.dataset.routeBenchmark,
		};

		// Range pill clicks: just update _histRange and re-render.
		const pills = document.getElementById('history-range-pills');
		if (pills) {
			pills.addEventListener('click', (e) => {
				const btn = e.target.closest('button[data-range]');
				if (!btn) return;
				_histRange = btn.dataset.range;
				document.querySelectorAll('#history-range-pills button').forEach(b =>
					b.classList.toggle('active', b.dataset.range === _histRange)
				);
				renderNetWorthChart();
			});
		}

		// Allow the global Update button (handled by dashboard.js-style flow
		// in other pages) to refresh charts when it completes. dashboard.js
		// exposes window.onUpdateComplete; we hook into the same name.
		window.onUpdateComplete = load;

		load();
	});
})();
