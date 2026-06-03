<?php
/** @var array $_ */
$routes = $_['routes'];
/**
 * Verbatim port of gbm-dashboard/app/analysis.html. Same shape as the
 * other ownCloud templates:
 *   1. Wrapped in <div id="gbm-app" class="analysis-page" data-route-*="...">.
 *   2. Inline <style> migrated to scoped #gbm-app.analysis-page rules in
 *      css/dashboard.css.
 *   3. Inline <script> moved into js/analysis.js (ownCloud CSP blocks inline).
 *      Chart.js vendored at js/vendor/chart.umd.min.js (no CDN).
 *   4. JSON fetched via routes.data.replace('__TYPE__', 'positions') instead
 *      of `/DATA/positions.json`. Benchmarks via routes.benchmark.
 */
?>
<div id="gbm-app" class="analysis-page"
	data-route-index="<?php p($routes['index']); ?>"
	data-route-orders="<?php p($routes['orders']); ?>"
	data-route-orders-all="<?php p($routes['orders_all']); ?>"
	data-route-dividends="<?php p($routes['dividends']); ?>"
	data-route-transactions="<?php p($routes['transactions']); ?>"
	data-route-glossary="<?php p($routes['glossary']); ?>"
	data-route-settings="<?php p($routes['settings']); ?>"
	data-route-analysis="<?php p($routes['analysis']); ?>"
	data-route-data="<?php p($routes['data']); ?>"
	data-route-config="<?php p($routes['config']); ?>"
	data-route-update="<?php p($routes['update']); ?>"
	data-route-benchmark="<?php p($routes['benchmark']); ?>">

	<h1>
		<div class="logo-box">GBM</div>
		Análisis
	</h1>
	<div class="subtitle">
		Visualizaciones del portafolio · Última actualización:
		<span id="last-update">—</span>
		<span id="last-update-age" class="staleness-chip"></span>
	</div>

	<div class="nav">
		<a href="<?php p($routes['index']); ?>">📊 Portafolio</a>
		<a href="<?php p($routes['orders']); ?>">📋 Movimientos</a>
		<a href="<?php p($routes['orders_all']); ?>">📜 Histórico</a>
		<a href="<?php p($routes['dividends']); ?>">💰 Dividendos</a>
		<a href="<?php p($routes['transactions']); ?>">📒 Libro Diario</a>
		<a href="<?php p($routes['analysis']); ?>" class="active">📈 Análisis</a>
		<a href="<?php p($routes['glossary']); ?>">📖 Glosario</a>
		<a href="<?php p($routes['settings']); ?>">⚙ Configuración</a>
	</div>

	<div id="error-box"></div>

	<!-- ---------- Composición por mercado ---------- -->
	<div class="section">
		<span>Composición del portafolio</span>
		<span class="badge muted" id="alloc-badge">por mercado</span>
	</div>
	<div class="chart-card">
		<div class="chart-container">
			<canvas id="allocation-chart"></canvas>
			<div id="allocation-empty" class="chart-empty" style="display:none;">
				Sin posiciones para graficar todavía.
			</div>
		</div>
	</div>

	<!-- ---------- Dividendos por mes ---------- -->
	<div class="section">
		<span>Dividendos por mes</span>
		<span class="badge muted" id="div-badge">últimos 12 meses</span>
	</div>
	<div class="stat-row" id="div-stats">
		<div>
			<div class="stat-label">Recibido (12m)</div>
			<div class="stat-value" id="div-received">—</div>
			<div class="stat-detail" id="div-received-detail">—</div>
		</div>
		<div>
			<div class="stat-label">ISR retenido (12m)</div>
			<div class="stat-value red" id="div-tax">—</div>
			<div class="stat-detail" id="div-tax-detail">—</div>
		</div>
		<div>
			<div class="stat-label">Proyección próximos 12m</div>
			<div class="stat-value green" id="div-forecast">—</div>
			<div class="stat-detail" id="div-forecast-detail">—</div>
		</div>
	</div>
	<div class="chart-card">
		<div class="chart-container">
			<canvas id="dividends-chart"></canvas>
			<div id="dividends-empty" class="chart-empty" style="display:none;">
				Sin dividendos en los últimos 12 meses.
			</div>
		</div>
	</div>

	<!-- ---------- Patrimonio en el tiempo (cost basis trajectory) ---------- -->
	<div class="section">
		<span>Capital invertido en el tiempo</span>
		<span class="badge muted" id="hist-badge">cost basis (compras − ventas)</span>
	</div>
	<div class="range-pills" id="history-range-pills">
		<button data-range="1M">1M</button>
		<button data-range="3M">3M</button>
		<button data-range="6M">6M</button>
		<button data-range="1Y">1Y</button>
		<button data-range="ALL" class="active">All</button>
	</div>
	<div class="chart-card">
		<div class="chart-container">
			<canvas id="history-chart"></canvas>
			<div id="history-empty" class="chart-empty" style="display:none;">
				Sin transacciones suficientes para graficar.
			</div>
		</div>
	</div>

	<div class="disclaimer">
		Dashboard no oficial — datos vía
		<a href="https://github.com/cdamken/gbm-mx-api" target="_blank" rel="noopener">gbm-mx-api</a>.
		Todo corre dentro de tu ownCloud, aislado por usuario.
		No afiliado con Grupo Bursátil Mexicano.
	</div>
</div>
