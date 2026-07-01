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
<div id="gbm-app" class="analysis-page" data-tab="analysis"
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
	data-route-benchmark="<?php p($routes['benchmark']); ?>"
	data-route-analysis-data="<?php p($routes['analysis_data']); ?>">

	<div class="subtitle">
		Visualizaciones del portafolio · Última actualización:
		<span id="last-update">—</span>
		<span id="last-update-age" class="staleness-chip"></span>
	</div>

	<div id="error-box"></div>

	<!-- ---------- Capital y resultados ---------- -->
	<div class="section">
		<span>Capital y resultados</span>
	</div>
	<div class="stat-row" id="capital-stats">
		<div>
			<div class="stat-label">Costo invertido</div>
			<div class="stat-value" id="cap-net">—</div>
			<div class="stat-detail">suma del costo de tus posiciones</div>
		</div>
		<div>
			<div class="stat-label">P&amp;L no realizado</div>
			<div class="stat-value" id="cap-pnl">—</div>
			<div class="stat-detail" id="cap-pnl-detail">valor − costo</div>
		</div>
		<div>
			<div class="stat-label">Compras totales</div>
			<div class="stat-value" id="cap-buys">—</div>
			<div class="stat-detail" id="cap-buys-detail">—</div>
		</div>
		<div>
			<div class="stat-label">Ventas totales</div>
			<div class="stat-value" id="cap-sells">—</div>
			<div class="stat-detail" id="cap-sells-detail">—</div>
		</div>
	</div>

	<!-- ---------- Ganadores y perdedores ---------- -->
	<div class="section">
		<span>Ganadores y perdedores</span>
		<span class="badge muted">retorno total · precio + dividendos</span>
	</div>
	<div class="chart-card">
		<table id="winners-losers-table">
			<thead>
				<tr>
					<th>Posición</th>
					<th class="num">Valor de mercado</th>
					<th class="num">P&amp;L de precio</th>
					<th class="num">Dividendos</th>
					<th class="num">Retorno total</th>
					<th class="num">Retorno %</th>
				</tr>
			</thead>
			<tbody id="winners-losers-tbody"></tbody>
		</table>
		<div id="winners-losers-empty" class="chart-empty" style="display:none;">
			Sin posiciones para analizar todavía.
		</div>
	</div>

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

	<!-- Dividend stats + monthly chart moved to the Dividendos page
	     (dividendos en Dividendos, análisis en Análisis). -->

	<!-- ---------- Patrimonio en el tiempo (cost basis trajectory) ---------- -->
	<div class="section">
		<span>Valor del portafolio en el tiempo</span>
		<span class="badge muted" id="hist-badge">valor real · crece cada día</span>
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
				El historial de valor se va llenando: el sistema guarda un punto
				por día. Vuelve en unos días para ver la curva.
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
