<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
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
		Portfolio Dashboard
	</h1>
	<div class="subtitle">
		<span id="contract-label">Contrato: cargando...</span> · Última actualización:
		<span id="last-update">—</span>
		<button class="update-btn" id="update-btn">⟳ Actualizar</button>
		<button class="settings-btn" id="settings-btn" title="Configurar credenciales">⚙ Cuenta</button>
	</div>

	<div class="nav">
		<a href="<?php p($routes['index']); ?>" class="active">📊 Portafolio</a>
		<a href="<?php p($routes['orders']); ?>">📋 Movimientos</a>
		<a href="<?php p($routes['orders_all']); ?>">📜 Histórico</a>
		<a href="<?php p($routes['dividends']); ?>">💰 Dividendos</a>
		<a href="<?php p($routes['transactions']); ?>">📒 Libro Diario</a>
	</div>

	<div id="error-box"></div>

	<div class="cards" id="cards">
		<div class="card">
			<div class="label">Valor total</div>
			<div class="value" id="total-value">—</div>
			<div class="delta muted">suma de todas las cuentas</div>
		</div>
		<div class="card">
			<div class="label">P&amp;L acumulado</div>
			<div class="value" id="total-pnl">—</div>
			<div class="delta" id="total-pnl-pct">—</div>
		</div>
		<div class="card">
			<div class="label">Posiciones</div>
			<div class="value" id="num-positions">—</div>
			<div class="delta muted">todas las cuentas</div>
		</div>
		<div class="card">
			<div class="label">Cuentas activas</div>
			<div class="value" id="num-accounts">—</div>
			<div class="delta muted">estrategias del contrato</div>
		</div>
	</div>

	<div class="section">
		<span>Cuentas</span>
		<span class="badge" id="accounts-count">—</span>
	</div>
	<div class="accounts-grid" id="accounts-grid"></div>

	<div class="section">
		<span>Top movimientos</span>
		<span class="badge muted">todas las cuentas</span>
	</div>
	<div class="movers-grid">
		<div>
			<h3 style="font-size:13px; color: var(--green); margin-bottom: 8px;">▲ Top ganadores</h3>
			<table>
				<thead><tr><th>Emisora</th><th class="num">Cantidad</th><th class="num">P&amp;L %</th><th class="num">P&amp;L MXN</th></tr></thead>
				<tbody id="top-winners"></tbody>
			</table>
		</div>
		<div>
			<h3 style="font-size:13px; color: var(--red); margin-bottom: 8px;">▼ Top perdedores</h3>
			<table>
				<thead><tr><th>Emisora</th><th class="num">Cantidad</th><th class="num">P&amp;L %</th><th class="num">P&amp;L MXN</th></tr></thead>
				<tbody id="top-losers"></tbody>
			</table>
		</div>
	</div>

	<div class="section">
		<span>Todas las posiciones</span>
		<span class="badge" id="positions-count">—</span>
	</div>

	<div class="controls">
		<input type="text" id="search" placeholder="Buscar emisora...">
		<select id="account-filter"><option value="">Todas las cuentas</option></select>
		<select id="market-filter">
			<option value="">Todos los mercados</option>
			<option value="mercado_capitales">BMV (México)</option>
			<option value="mercados_globales_sic">SIC (USA vía BMV)</option>
			<option value="mercado_extranjero">Extranjero (Trading USA)</option>
			<option value="sociedades_inversion_comun">Fondos Común</option>
			<option value="sociedades_inversion_deuda">Fondos Deuda</option>
		</select>
		<select id="pnl-filter">
			<option value="">P&amp;L: todos</option>
			<option value="winners">Solo ganadores (+)</option>
			<option value="losers">Solo perdedores (−)</option>
		</select>
	</div>

	<table id="positions-table">
		<thead>
			<tr>
				<th data-sort="issue_id">Emisora</th>
				<th>Mercado</th>
				<th data-sort="_account_name">Cuenta</th>
				<th class="num" data-sort="quantity">Cantidad</th>
				<th class="num" data-sort="average_price">Precio promedio</th>
				<th class="num" data-sort="last_price">Último precio</th>
				<th class="num" data-sort="market_value">Valor mercado</th>
				<th class="num" data-sort="yield_value">P&amp;L MXN</th>
				<th class="num" data-sort="historical_variation_percentage">P&amp;L %</th>
			</tr>
		</thead>
		<tbody id="positions-tbody"></tbody>
	</table>

	<div class="disclaimer">
		Dashboard no oficial — datos vía
		<a href="https://github.com/cdamken/gbm-mx-api" target="_blank" rel="noopener">gbm-mx-api</a>.
		Tus credenciales y datos viven solo en este servidor ownCloud, aislados por usuario.
		No afiliado con Grupo Bursátil Mexicano.
	</div>

	<div class="modal-backdrop" id="config-modal">
		<div class="modal">
			<h2>⚙ Configuración de cuenta GBM+</h2>
			<p>
				Estas credenciales se guardan cifradas en tu perfil de ownCloud y
				solo se usan desde tu sesión. Si tienes 2FA activado, el código TOTP
				se pedirá después al actualizar.
			</p>
			<div class="modal-error hidden" id="config-error"></div>
			<label for="config-email">Email</label>
			<input type="email" class="field" id="config-email" autocomplete="username" placeholder="tu-email@dominio.com">
			<label for="config-password">Contraseña</label>
			<input type="password" class="field" id="config-password" autocomplete="current-password" placeholder="••••••••">
			<div style="height: 20px;"></div>
			<div class="modal-btns">
				<button class="secondary" id="config-cancel">Cancelar</button>
				<button class="primary" id="config-submit" disabled>Guardar</button>
			</div>
			<div class="modal-hint">
				Cero telemetría. Cero envío fuera de este servidor.
			</div>
		</div>
	</div>

	<div class="progress-overlay" id="progress-overlay">
		<div class="progress-box">
			<div class="spinner"></div>
			<h2>Actualizando tu portafolio</h2>
			<div class="progress-stage" id="progress-stage">Conectando con GBM…</div>
			<div class="progress-hint">
				Esto puede tardar un par de minutos. Por favor, no cierres la pestaña.
			</div>
		</div>
	</div>

	<div class="modal-backdrop" id="totp-modal">
		<div class="modal">
			<h2>🔐 Código de seguridad</h2>
			<p>
				Tu sesión expiró. Abre tu app autenticadora y teclea el código de
				<b>6 dígitos</b> para GBM+.
			</p>
			<div class="modal-error hidden" id="totp-error"></div>
			<input type="text" class="totp" id="totp-input" maxlength="6" inputmode="numeric" autocomplete="off" placeholder="000000">
			<div class="modal-btns">
				<button class="secondary" id="totp-cancel">Cancelar</button>
				<button class="primary" id="totp-submit">Actualizar</button>
			</div>
			<div class="modal-hint">
				El código cambia cada 30 segundos. Sin guardar en disco.
			</div>
		</div>
	</div>

</div>
