<?php
/** @var array $_ */
$routes = $_['routes'];
?>
<div id="gbm-app" data-tab="orders"
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
		Histórico completo · <span id="range-label">Rango: cargando...</span> ·
		Cuentas: <span id="account-label">—</span> ·
		Última actualización: <span id="last-update">—</span>
	</div>

	<div id="error-box"></div>

	<div class="cards">
		<div class="card">
			<div class="label">Total órdenes</div>
			<div class="value" id="num-total">—</div>
			<div class="delta muted">en el rango</div>
		</div>
		<div class="card">
			<div class="label">Realizadas (llenas)</div>
			<div class="value green" id="num-filled">—</div>
			<div class="delta muted" id="filled-amount">—</div>
		</div>
		<div class="card">
			<div class="label">Canceladas</div>
			<div class="value red" id="num-cancelled">—</div>
			<div class="delta muted" id="cancelled-amount">—</div>
		</div>
		<div class="card">
			<div class="label">Otras (pendientes / parciales)</div>
			<div class="value amber" id="num-other">—</div>
			<div class="delta muted" id="other-amount">—</div>
		</div>
	</div>

	<div class="section">
		<span>Todas las órdenes</span>
		<span class="badge" id="orders-count">—</span>
	</div>

	<div class="controls">
		<input type="text" id="search" placeholder="Buscar emisora...">
		<select id="status-filter">
			<option value="">Todos los estados</option>
			<option value="filled">Solo realizadas</option>
			<option value="cancelled">Solo canceladas</option>
			<option value="other">Solo pendientes / otras</option>
		</select>
		<select id="side-filter">
			<option value="">Compras y ventas</option>
			<option value="BUY">Solo compras</option>
			<option value="SELL">Solo ventas</option>
		</select>
		<select id="month-filter"><option value="">Todos los meses</option></select>
		<select id="ticker-filter"><option value="">Todas las emisoras</option></select>
		<span id="account-filter-wrap" style="display: none;">
			<select id="account-filter"><option value="">Todas las cuentas</option></select>
		</span>
	</div>

	<table id="orders-table">
		<thead>
			<tr>
				<th data-sort="processed_at">Fecha / Hora</th>
				<th data-sort="issue_id">Emisora</th>
				<th data-sort="account_name">Cuenta</th>
				<th data-sort="side">Tipo</th>
				<th data-sort="status">Estado</th>
				<th class="num" data-sort="original_quantity">Pedido</th>
				<th class="num" data-sort="assigned_quantity">Asignado</th>
				<th class="num" data-sort="average_price">Precio prom.</th>
				<th class="num" data-sort="amount">Importe</th>
				<th>ID</th>
			</tr>
		</thead>
		<tbody id="orders-tbody"></tbody>
	</table>

	<div class="disclaimer">
		Dashboard no oficial — datos vía
		<a href="https://github.com/cdamken/gbm-mx-api" target="_blank" rel="noopener">gbm-mx-api</a>.
		Esta página muestra <b>todas las órdenes</b> independientemente de su estado
		(realizadas, canceladas, pendientes o parciales). Para ver solo las
		realizadas, ve a <a href="<?php p($routes['orders']); ?>">📋 Movimientos</a>.
	</div>

</div>
