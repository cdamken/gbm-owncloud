<?php
/** @var array $_ */
$routes = $_['routes'];
?>
<div id="gbm-app" data-tab="settings"
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
	data-route-settings-api="<?php p($routes['settings_api']); ?>"
	data-route-reset="<?php p($routes['reset']); ?>"
	data-route-export-csv="<?php p($routes['export_csv']); ?>"
	data-route-update="<?php p($routes['update']); ?>"
	data-route-generate-fiscal="<?php p($routes['generate_fiscal']); ?>">

	<div class="subtitle">
		Credenciales, rangos de descarga, e información de la sesión.
	</div>

	<div class="settings-grid">
		<aside class="settings-side">
			<a href="#s-account" class="active" data-section="s-account">👤 Cuenta</a>
			<a href="#s-data" data-section="s-data">📊 Rangos de datos</a>
			<a href="#s-session" data-section="s-session">🔐 Sesión</a>
			<a href="#s-about" data-section="s-about">ℹ️ Acerca de</a>
		</aside>

		<div>
			<!-- Cuenta GBM+ -->
			<section class="settings-section" id="s-account">
				<h2>👤 Cuenta GBM+</h2>
				<p class="section-desc">
					Email y contraseña para autenticar con GBM+. Se guardan cifradas
					en tu perfil de ownCloud, aisladas por usuario. Cambiar de email
					aquí vacía la sesión y el caché; el siguiente ⟳ Actualizar pedirá
					TOTP de la nueva cuenta.
				</p>
				<div class="settings-row">
					<label for="email-input">Email</label>
					<!-- autocomplete="off" + a non-login-like name keeps the browser
					     password manager from autofilling SAVED credentials (e.g. the
					     ownCloud login) into the GBM field and from offering to save
					     these GBM creds. These are GBM credentials, not a site login. -->
					<input type="email" id="email-input" name="gbm-account-email"
						autocomplete="off"
						placeholder="tu-email@dominio.com">
				</div>
				<div class="settings-row">
					<label for="password-input">Contraseña</label>
					<!-- "new-password" is the documented lever that stops Chrome
					     autofilling a stored password here; combined with the AJAX
					     save (no form submit) it also suppresses the "save password?"
					     prompt in practice. -->
					<input type="password" id="password-input" name="gbm-account-pass"
						autocomplete="new-password"
						placeholder="••••••••">
				</div>
				<div class="flash" id="account-flash"></div>
				<div class="settings-actions">
					<button class="primary" id="save-account-btn" type="button">Guardar credenciales</button>
					<button class="ghost" id="switch-account-btn" type="button"
						title="Limpiar los campos para conectarte a otra cuenta GBM+">
						🔄 Cambiar a otra cuenta…
					</button>
				</div>
			</section>

			<!-- Días hacia atrás -->
			<section class="settings-section" id="s-data">
				<h2>📊 Rangos de datos</h2>
				<p class="section-desc">
					Cuántos días hacia atrás bajar al hacer ⟳ Actualizar. Valores
					mayores → más datos pero el fetch tarda más. Defaults: órdenes
					10 años (3650 días) — la ventana máxima validada.
					<br><br>
					<b>Tip</b>: la API de GBM no impone un límite duro de fecha. Si
					quieres que la <b>línea de patrimonio</b> en Análisis empiece a
					tener sentido (cuando esa página esté disponible), sube "Libro
					Diario" a 1095 (3 años) o más.
				</p>
				<div class="settings-row">
					<label for="orders-days-input">Órdenes (días)</label>
					<input type="number" id="orders-days-input" min="1" max="3650">
				</div>
				<div class="settings-row">
					<label for="dividends-days-input">Dividendos (días)</label>
					<input type="number" id="dividends-days-input" min="1" max="3650">
				</div>
				<div class="settings-row">
					<label for="transactions-days-input">Libro Diario (días)</label>
					<input type="number" id="transactions-days-input" min="1" max="3650">
				</div>
				<div class="flash" id="data-flash"></div>
				<div class="settings-actions">
					<button class="primary" id="save-data-btn" type="button">Guardar rangos</button>
					<a class="settings-actions-link"
					   id="export-csv-link"
					   href="<?php p($routes['export_csv']); ?>"
					   download
					   title="Descarga un CSV en español listo para pasar al contador o importar a Excel.">
						📥 Exportar CSV para SAT
					</a>
					<button class="primary" id="generate-fiscal-btn" type="button"
						title="Genera CSVs de dividendos/intereses/retención por año en tu carpeta GBM/. Estimación informativa; la constancia fiscal de GBM es la oficial.">
						📄 Generar reporte fiscal
					</button>
				</div>
			</section>

			<!-- Sesión -->
			<section class="settings-section" id="s-session">
				<h2>🔐 Sesión</h2>
				<p class="section-desc">
					El access token de Cognito dura 1 hora. El refresh token dura
					~30 días — gbm-mx-api lo usa silenciosamente para renovar el
					access sin pedirte TOTP. Si revocas la sesión, el próximo
					⟳ Actualizar abre el modal TOTP.
				</p>
				<ul class="about-list" id="session-info">
					<li><span class="label">Cargando…</span><span class="value">—</span></li>
				</ul>
				<div class="flash" id="session-flash"></div>
				<div class="settings-actions">
					<a class="settings-actions-link" href="<?php p($routes['index']); ?>"
						title="El botón ⟳ Actualizar vive en Portafolio.">
						Refrescar sesión (ir a Portafolio)
					</a>
					<button class="danger" id="revoke-session-btn" type="button">
						Cerrar sesión y limpiar caché
					</button>
				</div>
			</section>

			<!-- Acerca de -->
			<section class="settings-section" id="s-about">
				<h2>ℹ️ Acerca de</h2>
				<p class="section-desc">
					Dashboard de GBM+ aislado por usuario en ownCloud.
				</p>
				<ul class="about-list">
					<li><span class="label">Versión de la app</span><span class="value" id="about-app-version">—</span></li>
					<li><span class="label">Versión de gbm-mx-api</span><span class="value" id="about-api-version">—</span></li>
					<li><span class="label">Credenciales</span><span class="value">cifradas en oc_preferences (por usuario)</span></li>
					<li><span class="label">Datos</span><span class="value">{data_dir}/{user}/gbm/</span></li>
					<li><span class="label">Código</span><span class="value">
						<a href="https://github.com/cdamken/gbm-owncloud" target="_blank">github.com/cdamken/gbm-owncloud</a>
					</span></li>
				</ul>
			</section>
		</div>
	</div>

	<div class="disclaimer">
		Dashboard no oficial — datos vía
		<a href="https://github.com/cdamken/gbm-mx-api" target="_blank" rel="noopener">gbm-mx-api</a>.
		No afiliado con Grupo Bursátil Mexicano.
	</div>
</div>
