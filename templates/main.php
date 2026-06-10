<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$routes = $_['routes'];
?>
<div id="gbm-app" data-tab="portfolio"
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
		<span id="contract-label">Contrato: cargando...</span> · Última actualización:
		<span id="last-update">—</span>
		<span id="last-update-age" class="staleness-chip"></span>
	</div>

	<div id="error-box"></div>

	<div id="concentration-warning"></div>

	<div class="cards" id="cards">
		<div class="card">
			<div class="label">Valor total</div>
			<div class="value" id="total-value">—</div>
			<div class="delta muted">suma de todas las cuentas</div>
		</div>
		<div class="card" title="Costo total — suma del precio promedio × cantidad de todas tus posiciones. Capital comprometido en instrumentos. Equivalente al 'Investment Cost' del dashboard de TR.">
			<div class="label">Costo invertido</div>
			<div class="value" id="investment-cost">—</div>
			<div class="delta muted">suma de compras netas</div>
		</div>
		<div class="card">
			<div class="label">P&amp;L acumulado</div>
			<div class="value" id="total-pnl">—</div>
			<div class="delta" id="total-pnl-pct">—</div>
		</div>
		<div class="card" title="Retorno anualizado money-weighted (XIRR) usando depósitos/retiros externos como flujos y el valor actual del portafolio como terminal. La ventana de transacciones es configurable en Configuración (default 10 años) — si tu XIRR muestra '—', sube los días para incluir tus depósitos más antiguos.">
			<div class="label">XIRR (anualizado)</div>
			<div class="value" id="xirr-value">—</div>
			<div class="delta muted" id="xirr-detail">basado en flujos externos</div>
		</div>
		<div class="card" title="Cash sin invertir en cuentas Smart Cash. GBM hace barrido automático a money market funds, así que típicamente es $0 — solo crece si vendiste algo y aún no recompraste.">
			<div class="label">Cash disponible</div>
			<div class="value" id="available-cash">—</div>
			<div class="delta muted">Smart Cash sin reinvertir</div>
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
		<a href="<?php p(str_replace('__KIND__', 'posiciones', $routes['export_page'])); ?>" download="gbm-posiciones.csv"
		   style="background: rgba(96,165,250,0.08); color: var(--blue); text-decoration: none; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 500; border: 1px solid var(--blue); white-space: nowrap;"
		   title="Descarga el portafolio como CSV (ticker, cuenta, cantidad, precio promedio, último precio, valor mercado, P&L MXN, P&L %)">↓ Exportar CSV</a>
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

	<!-- Non-blocking update flow (ported from Trade-Republic-owncloud).
	     Was a centered progress modal that blocked the viewport; now a
	     thin top bar + a toast under the top-bar so the page stays
	     interactive while the fetch runs. -->
	<div id="progress-bar" class="progress-bar"></div>
	<div id="toast" class="toast">
		<button id="toast-close-btn" class="t-close" aria-label="Cerrar">×</button>
		<div class="t-title">
			<span class="spin"></span>
			<span id="toast-title">Actualizando tu portafolio</span>
		</div>
		<div class="t-stage" id="progress-stage">Conectando con GBM…</div>
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
			<label class="modal-checkbox" style="display:flex; align-items:flex-start; gap:8px; font-size:12px; color: var(--muted); margin: -4px 0 16px; cursor: pointer; line-height: 1.4;">
				<input type="checkbox" id="totp-full-reload" style="margin-top: 2px;">
				<span>Recargar <b>todo desde cero</b> (descarga lenta — solo cuando cambiaste de cuenta o quieres limpiar datos viejos)</span>
			</label>
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
