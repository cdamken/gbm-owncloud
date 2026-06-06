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
		<span id="range-label">Rango: cargando...</span> ·
		Cuenta: <span id="account-label">—</span> ·
		Última actualización: <span id="last-update">—</span>
	</div>

	<div id="error-box"></div>

	<div class="cards">
		<div class="card">
			<div class="label">Transacciones</div>
			<div class="value" id="num-orders">—</div>
			<div class="delta muted" id="num-orders-note">en el rango</div>
		</div>
		<div class="card">
			<div class="label">Total invertido (compras)</div>
			<div class="value" id="total-buy">—</div>
			<div class="delta muted">suma de Importes Compra</div>
		</div>
		<div class="card">
			<div class="label">Total ventas</div>
			<div class="value" id="total-sell">—</div>
			<div class="delta muted">suma de Importes Venta</div>
		</div>
		<div class="card">
			<div class="label">Comisiones pagadas</div>
			<div class="value" id="total-commission">—</div>
			<div class="delta muted">total acumulado</div>
		</div>
	</div>

	<div class="section">
		<span>Resumen por mes</span>
		<span class="badge" id="months-count">—</span>
	</div>
	<table>
		<thead>
			<tr>
				<th>Mes</th>
				<th class="num">Compras</th>
				<th class="num">Monto compras</th>
				<th class="num">Ventas</th>
				<th class="num">Monto ventas</th>
				<th class="num">Comisiones</th>
			</tr>
		</thead>
		<tbody id="months-tbody"></tbody>
	</table>

	<div class="section">
		<span>Top emisoras</span>
		<span class="badge muted">por monto invertido</span>
	</div>
	<table>
		<thead>
			<tr>
				<th>Emisora</th>
				<th class="num"># Operaciones</th>
				<th class="num">Títulos comprados</th>
				<th class="num">Monto comprado</th>
				<th class="num">Precio promedio</th>
			</tr>
		</thead>
		<tbody id="tickers-tbody"></tbody>
	</table>

	<div class="section">
		<span>Todas las transacciones</span>
		<span class="badge" id="orders-count">—</span>
	</div>

	<div class="controls">
		<input type="text" id="search" placeholder="Buscar emisora...">
		<select id="side-filter">
			<option value="">Todas las operaciones</option>
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
				<th class="num" data-sort="quantity">Cantidad</th>
				<th class="num" data-sort="average_price">Precio</th>
				<th class="num" data-sort="amount">Importe</th>
				<th class="num" data-sort="commission">Comisión</th>
				<th>ID</th>
			</tr>
		</thead>
		<tbody id="orders-tbody"></tbody>
	</table>

	<div class="disclaimer">
		Dashboard no oficial — datos vía
		<a href="https://github.com/cdamken/gbm-mx-api" target="_blank" rel="noopener">gbm-mx-api</a>.
		Solo se muestran órdenes con estatus <b>Llena</b> de la cuenta principal de trading.
	</div>

</div>
