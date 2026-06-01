<?php
/** @var array $_ */
$routes = $_['routes'];
?>
<div id="gbm-app"
	data-route-index="<?php p($routes['index']); ?>"
	data-route-orders="<?php p($routes['orders']); ?>"
	data-route-orders-all="<?php p($routes['orders_all']); ?>"
	data-route-dividends="<?php p($routes['dividends']); ?>"
	data-route-transactions="<?php p($routes['transactions']); ?>"
	data-route-data="<?php p($routes['data']); ?>"
	data-route-config="<?php p($routes['config']); ?>"
	data-route-update="<?php p($routes['update']); ?>">

	<h1>
		<div class="logo-box">GBM</div>
		Libro Diario
	</h1>
	<div class="subtitle">
		<span id="range-label">Rango: cargando...</span> ·
		Última actualización: <span id="last-update">—</span>
	</div>

	<div class="nav">
		<a href="<?php p($routes['index']); ?>">📊 Portafolio</a>
		<a href="<?php p($routes['orders']); ?>">📋 Movimientos</a>
		<a href="<?php p($routes['orders_all']); ?>">📜 Histórico</a>
		<a href="<?php p($routes['dividends']); ?>">💰 Dividendos</a>
		<a href="<?php p($routes['transactions']); ?>" class="active">📒 Libro Diario</a>
	</div>

	<div id="error-box"></div>

	<div class="cards">
		<div class="card">
			<div class="label">Compras</div>
			<div class="value blue" id="total-buys">—</div>
			<div class="delta" id="buys-detail">—</div>
		</div>
		<div class="card">
			<div class="label">Ventas</div>
			<div class="value purple" id="total-sells">—</div>
			<div class="delta" id="sells-detail">—</div>
		</div>
		<div class="card">
			<div class="label">Depósitos</div>
			<div class="value green" id="total-deposits">—</div>
			<div class="delta" id="deposits-detail">—</div>
		</div>
		<div class="card">
			<div class="label">Retiros</div>
			<div class="value red" id="total-withdrawals">—</div>
			<div class="delta" id="withdrawals-detail">—</div>
		</div>
		<div class="card">
			<div class="label">Movimientos</div>
			<div class="value" id="total-movements">—</div>
			<div class="delta" id="movements-detail">—</div>
		</div>
	</div>

	<div class="section">
		<span>Detalle del Libro Diario</span>
		<span class="badge" id="rows-count">—</span>
	</div>

	<div class="controls">
		<input type="text" id="search" placeholder="Buscar emisora o descripción...">
		<select id="category-filter">
			<option value="">Todas las categorías</option>
			<option value="buy_stock">Compra de Acciones</option>
			<option value="sell_stock">Venta de Acciones</option>
			<option value="buy_fund">Compra Soc. de Inv.</option>
			<option value="sell_fund">Venta Soc. de Inv.</option>
			<option value="repo_buy">Compra en Reporto</option>
			<option value="repo_mature">Vencimiento de Reporto</option>
			<option value="deposit">Traspasos (entran)</option>
			<option value="withdrawal">Traspasos (salen)</option>
			<option value="external_deposit">Depósitos externos (otro titular GBM)</option>
			<option value="external_withdrawal">Retiros externos (a otro titular GBM)</option>
			<option value="fx">Divisas</option>
			<option value="dividend">Dividendos</option>
			<option value="tax_withholding">ISR retenido</option>
			<option value="other">Otros</option>
		</select>
		<select id="month-filter"><option value="">Todos los meses</option></select>
		<select id="account-filter"><option value="">Todas las cuentas</option></select>
	</div>

	<table id="tx-table">
		<thead>
			<tr>
				<th data-sort="process_date">Fecha</th>
				<th data-sort="account_name">Cuenta</th>
				<th>Categoría</th>
				<th data-sort="security_id">Emisora</th>
				<th>Descripción</th>
				<th class="num" data-sort="amount">Monto</th>
				<th>ID</th>
			</tr>
		</thead>
		<tbody id="tx-tbody"></tbody>
	</table>

	<div class="disclaimer">
		Dashboard no oficial — datos vía
		<a href="https://github.com/cdamken/gbm-mx-api" target="_blank" rel="noopener">gbm-mx-api</a>.
		El <b>Libro Diario</b> es el ledger contable completo de GBM: incluye
		<b>todos</b> los movimientos del periodo en todas las cuentas
		(Personal, Asesor, Smart Cash) — compras y ventas de acciones y
		fondos, reportos, traspasos de efectivo, divisas, dividendos e ISR.
	</div>

</div>
