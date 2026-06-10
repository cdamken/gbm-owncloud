<?php
/** @var array $_ */
$routes = $_['routes'];
?>
<div id="gbm-app" data-tab="dividends"
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
	data-route-update="<?php p($routes['update']); ?>">

	<div class="subtitle">
		<span id="range-label">Rango: cargando...</span> ·
		Última actualización: <span id="last-update">—</span>
	</div>

	<div id="error-box"></div>

	<div class="cards">
		<div class="card">
			<div class="label">Total recibido (neto)</div>
			<div class="value green" id="total-net">—</div>
			<div class="delta" id="total-detail">—</div>
		</div>
		<div class="card">
			<div class="label">Bruto</div>
			<div class="value" id="total-gross">—</div>
			<div class="delta muted">antes de impuestos</div>
		</div>
		<div class="card">
			<div class="label">ISR retenido</div>
			<div class="value red" id="total-tax">—</div>
			<div class="delta muted">retenciones cedulares</div>
		</div>
		<div class="card">
			<div class="label">Emisoras pagadoras</div>
			<div class="value" id="num-issuers">—</div>
			<div class="delta" id="num-events">—</div>
		</div>
	</div>

	<div class="section">
		<span>Detalle de dividendos</span>
		<span class="badge" id="rows-count">—</span>
	</div>

	<div class="controls">
		<input type="text" id="search" placeholder="Buscar emisora o descripción...">
		<select id="kind-filter">
			<option value="">Todos los movimientos</option>
			<option value="payout">Solo abonos (efectivo)</option>
			<option value="tax">Solo ISR retenido</option>
		</select>
		<select id="month-filter"><option value="">Todos los meses</option></select>
		<select id="ticker-filter"><option value="">Todas las emisoras</option></select>
		<span id="account-filter-wrap" style="display: none;">
			<select id="account-filter"><option value="">Todas las cuentas</option></select>
		</span>
		<a href="<?php p(str_replace('__KIND__', 'dividendos', $routes['export_page'])); ?>" download="gbm-dividendos.csv"
		   style="background: rgba(96,165,250,0.08); color: var(--blue); text-decoration: none; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 500; border: 1px solid var(--blue); white-space: nowrap;"
		   title="Descarga los dividendos como CSV (fecha, ticker, descripción, monto bruto, ISR retenido, monto neto)">↓ Exportar CSV</a>
	</div>

	<table id="dividends-table">
		<thead>
			<tr>
				<th data-sort="process_date">Fecha</th>
				<th data-sort="security_id">Emisora</th>
				<th>Descripción</th>
				<th data-sort="account_name">Cuenta</th>
				<th>Tipo</th>
				<th class="num" data-sort="amount">Bruto</th>
				<th class="num" data-sort="net_amount">Neto</th>
				<th>ID</th>
			</tr>
		</thead>
		<tbody id="dividends-tbody"></tbody>
	</table>

	<div class="disclaimer">
		Dashboard no oficial — datos vía
		<a href="https://github.com/cdamken/gbm-mx-api" target="_blank" rel="noopener">gbm-mx-api</a>.
		Esta página muestra los movimientos que GBM clasifica como dividendos
		en tu portafolio: pagos en efectivo, reembolsos de capital, resultados
		fiscales distribuidos y las retenciones de ISR cedular correspondientes.
	</div>

</div>
