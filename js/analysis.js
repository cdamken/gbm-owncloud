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

	// Shared formatter — single source of truth in js/_shared.js (v0.14.18).
	const fmtMoney = window.fmtMoney;
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
			// ^SP500TR is the S&P 500 Total Return index — dividends
			// reinvested, no expense ratio. Cleanest possible benchmark
			// (mathematically equivalent to a hypothetical zero-cost
			// accumulating ETF). ^GSPC would miss dividends and SPY/VOO
			// drag ~0.03-0.09%/year + distribute dividends out.
			state.benchmarks = await Promise.all([
				safeJson(benchmarkUrl('NAFTRACISHRS.MX')),
				safeJson(benchmarkUrl('^SP500TR')),
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
			renderCapitalStats();
			renderAllocationChart();
			renderNetWorthChart();
		} catch (err) {
			document.getElementById('error-box').innerHTML =
				'<div class="error"><b>No se pudieron cargar los datos.</b><br>' +
				'Detalle: ' + (err && err.message ? err.message : String(err)) + '</div>';
		}
	}

	// Capital & results KPIs — parity with the TR/SC analytics cockpits.
	// Net capital = deposits − withdrawals; Lifetime P&L = current market
	// value − net capital; total purchases/sales = Σ is_buy / Σ is_sell
	// (money-market sweeps + repos excluded, same as the cost-basis line).
	function renderCapitalStats() {
		const rows = (state.transactions && state.transactions.transactions) || [];
		let deposits = 0, withdrawals = 0, buys = 0, sells = 0, buyCount = 0, sellCount = 0;
		for (const t of rows) {
			const cat = t.category;
			const amt = Math.abs(Number(t.amount) || 0);
			if (cat === 'deposit')    { deposits += amt; continue; }
			if (cat === 'withdrawal') { withdrawals += amt; continue; }
			if (cat === 'repo_buy' || cat === 'repo_mature') continue;
			if (t.security_id === 'GBMF2 BF' || t.security_id === 'GBMDINT BO') continue;
			if (t.is_buy)       { buys += amt; buyCount++; }
			else if (t.is_sell) { sells += amt; sellCount++; }
		}
		const netCapital = deposits - withdrawals;
		const currentValue = (state.positionsFlat || [])
			.reduce((s, p) => s + (Number(p.market_value) || 0), 0);
		const pnl = currentValue - netCapital;
		const m0 = (v) => fmtMoney(v, { currency: true, decimals: 0 });

		document.getElementById('cap-net').textContent = m0(netCapital);
		const pnlEl = document.getElementById('cap-pnl');
		pnlEl.textContent = (pnl >= 0 ? '+' : '−') + m0(Math.abs(pnl));
		pnlEl.className = 'stat-value ' + (pnl >= 0 ? 'green' : 'red');
		document.getElementById('cap-pnl-detail').textContent =
			'valor actual ' + m0(currentValue) + ' − capital ' + m0(netCapital);
		document.getElementById('cap-buys').textContent = m0(buys);
		document.getElementById('cap-buys-detail').textContent =
			buyCount + (buyCount === 1 ? ' orden' : ' órdenes') + ' de compra';
		document.getElementById('cap-sells').textContent = m0(sells);
		document.getElementById('cap-sells-detail').textContent =
			sellCount + (sellCount === 1 ? ' orden' : ' órdenes') + ' de venta';
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

		// Daily-close map (we ask Yahoo for interval=1d).
		const benchByDay = {};
		for (const h of bench.history) benchByDay[h.date] = h.close;
		const sortedBenchDates = Object.keys(benchByDay).sort();
		if (sortedBenchDates.length === 0) return null;

		// Walk every calendar day from user's first trade to today,
		// carrying forward the last benchmark close on weekends/holidays.
		// Emits one value per day → smooth line.
		const userDates = [...dailyMap.keys()].sort();
		const startDate = new Date(userDates[0] + 'T00:00:00Z');
		const lastBenchDate = sortedBenchDates[sortedBenchDates.length - 1];
		const endDate = new Date(lastBenchDate + 'T00:00:00Z');
		const today = new Date();
		if (today > endDate) endDate.setTime(today.getTime());

		let units = 0;
		let prevCostBasis = null;
		let lastClose = null;
		const out = {};

		for (let cur = new Date(startDate); cur <= endDate;
		     cur.setUTCDate(cur.getUTCDate() + 1)) {
			const dateStr = cur.toISOString().slice(0, 10);
			if (benchByDay[dateStr] != null) lastClose = benchByDay[dateStr];

			if (dailyMap.has(dateStr)) {
				const cb = dailyMap.get(dateStr);
				const delta = prevCostBasis == null ? cb : (cb - prevCostBasis);
				if (delta !== 0 && lastClose != null && lastClose > 0) {
					units += delta / lastClose;
				}
				prevCostBasis = cb;
			}

			if (lastClose != null && lastClose > 0 && units !== 0) {
				out[dateStr] = +(units * lastClose).toFixed(2);
			}
		}
		return out;
	}

	// ----------------------------------------------------------------------
	// Net-worth chart helpers (extracted from renderNetWorthChart in
	// v0.14.19 — the function had ballooned to 216 lines mixing data prep,
	// dataset construction, and Chart.js options).
	// ----------------------------------------------------------------------

	/**
	 * Walk the transaction list and accumulate a cost-basis trajectory
	 * (capital deployed minus capital withdrawn). Filters the same system
	 * noise as Libro Diario's "Mostrar ruido" toggle: deposits/withdrawals,
	 * repo round-trips, and the GBMF2/GBMDINT money-market sweeps that
	 * auto-roll idle cash.
	 */
	function _buildNetWorthDailyMap(rows) {
		const sorted = [...rows].sort(
			(a, b) => (a.process_date || '').localeCompare(b.process_date || '')
		);
		let running = 0;
		const dailyMap = new Map();
		for (const t of sorted) {
			const date = (t.process_date || '').slice(0, 10);
			if (!date) continue;
			const cat = t.category;
			if (cat === 'deposit' || cat === 'withdrawal') continue;
			if (cat === 'repo_buy' || cat === 'repo_mature') continue;
			// Money-market sweeps (peso GBMF2 BF, dollar GBMDINT BO).
			// Auto-roll of idle cash, not capital deployment.
			if (t.security_id === 'GBMF2 BF' || t.security_id === 'GBMDINT BO') continue;
			const amt = Math.abs(Number(t.amount) || 0);
			if (t.is_buy)       running += amt;
			else if (t.is_sell) running -= amt;
			else continue;
			dailyMap.set(date, Math.round(running * 100) / 100);
		}
		return dailyMap;
	}

	/**
	 * Pads the daily map up to today (carrying forward the last value) and
	 * filters down to the user's chosen range (1M / 3M / 6M / 1Y / all).
	 * Returns the sorted list of in-range dates.
	 */
	function _filterRangeDates(dailyMap, range) {
		const today = new Date().toISOString().slice(0, 10);
		const datesSorted = [...dailyMap.keys()].sort();
		const lastDate = datesSorted[datesSorted.length - 1];
		if (lastDate !== today) {
			dailyMap.set(today, dailyMap.get(lastDate));
			datesSorted.push(today);
		}
		const rangeDays = { '1M': 30, '3M': 90, '6M': 180, '1Y': 365 }[range];
		if (!rangeDays) return datesSorted;
		const cutoff = new Date();
		cutoff.setDate(cutoff.getDate() - rangeDays);
		const cutoffStr = cutoff.toISOString().slice(0, 10);
		const filtered = datesSorted.filter(d => d >= cutoffStr);
		return filtered.length === 0 ? [datesSorted[datesSorted.length - 1]] : filtered;
	}

	/**
	 * Reusable Chart.js dataset shape for the net-worth chart. The user's
	 * cost-basis is filled + bold; benchmarks are dashed lines on top.
	 */
	function _netWorthDataset(label, data, color, isUserCurve) {
		return {
			label,
			data,
			borderColor: color,
			backgroundColor: isUserCurve ? 'rgba(96, 165, 250, 0.10)' : 'transparent',
			borderWidth: 2,
			borderDash: isUserCurve ? undefined : (color === '#fbbf24' ? [6, 4] : [2, 4]),
			fill: !!isUserCurve,
			tension: 0.15,
			pointRadius: 0,
			pointHoverRadius: 5,
			pointHoverBackgroundColor: color,
			pointHoverBorderColor: '#0f1419',
			pointHoverBorderWidth: 2,
			spanGaps: !isUserCurve,
		};
	}

	/**
	 * Build the Chart.js options object for the net-worth chart. Pulled
	 * out so the orchestrator stays readable; this is pure config.
	 */
	function _netWorthChartOptions(datasetsCount) {
		return {
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
					display: datasetsCount > 1,
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
		};
	}

	function renderNetWorthChart() {
		if (typeof window.Chart !== 'function') return;
		const canvas = document.getElementById('history-chart');
		const emptyEl = document.getElementById('history-empty');
		if (!canvas) return;

		const showEmpty = () => {
			if (_histChart) { _histChart.destroy(); _histChart = null; }
			canvas.style.display = 'none';
			emptyEl.style.display = 'flex';
		};

		const rows = (state.transactions && state.transactions.transactions) || [];
		if (rows.length === 0) { showEmpty(); return; }

		const dailyMap = _buildNetWorthDailyMap(rows);
		if (dailyMap.size === 0) { showEmpty(); return; }

		const filteredDates = _filterRangeDates(dailyMap, _histRange);
		const labels = filteredDates.map(d => d);
		const values = filteredDates.map(d => dailyMap.get(d));

		// Header badge: date range + min/max value
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

		// Build benchmark curves (NAFTRAC + S&P 500 TR). _replayBenchmark
		// returns null when the benchmark has no data — we just skip it.
		const alignBench = (m) =>
			m ? filteredDates.map(d => d in m ? m[d] : null) : null;
		// Rebase each benchmark so it STARTS at the same height as the user's
		// line at the left edge of the visible window (subtract the pre-window
		// head-start) — otherwise an index that already ran up before the
		// window looks like it "starts higher". No-op in the "All" view.
		const rebaseToStart = (series) => {
			if (!series) return series;
			let i = 0;
			while (i < series.length && (series[i] == null || values[i] == null)) i++;
			if (i >= series.length) return series;
			const offset = series[i] - values[i];
			return series.map(v => v == null ? null : +(v - offset).toFixed(2));
		};
		const naftracValues = rebaseToStart(alignBench(_replayBenchmark(state.benchmarks[0], dailyMap)));
		const sp500Values   = rebaseToStart(alignBench(_replayBenchmark(state.benchmarks[1], dailyMap)));

		const datasets = [
			_netWorthDataset('Capital invertido (cost basis)', values, '#60a5fa', true),
		];
		if (naftracValues && naftracValues.some(v => v != null)) {
			datasets.push(_netWorthDataset('Si compraras NAFTRAC en su lugar', naftracValues, '#fbbf24', false));
		}
		if (sp500Values && sp500Values.some(v => v != null)) {
			datasets.push(_netWorthDataset('Si invirtieras en el S&P 500 (Total Return) en su lugar', sp500Values, '#4ade80', false));
		}

		if (_histChart) _histChart.destroy();
		_histChart = new window.Chart(canvas, {
			type: 'line',
			data: { labels, datasets },
			options: _netWorthChartOptions(datasets.length),
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
