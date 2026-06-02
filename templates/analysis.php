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
	data-route-glossary="<?php p($routes['glossary']); ?>"
	data-route-settings="<?php p($routes['settings']); ?>"
	data-route-analysis="<?php p($routes['analysis']); ?>"
	data-route-data="<?php p($routes['data']); ?>"
	data-route-config="<?php p($routes['config']); ?>"
	data-route-update="<?php p($routes['update']); ?>">

	<h1>
		<div class="logo-box">GBM</div>
		Análisis
	</h1>
	<div class="subtitle">
		Visualizaciones del portafolio (charts, XIRR, benchmarks).
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

	<div class="settings-section" style="text-align: center; padding: 40px 28px;">
		<h2 style="display:block; margin-bottom: 12px;">📈 Próximamente</h2>
		<p class="section-desc" style="margin: 0 auto; max-width: 540px;">
			La página de <b>Análisis</b> (ring chart de distribución, dividendos
			mensuales con ISR apilado, línea de capital invertido en el tiempo,
			XIRR y overlay de benchmarks NAFTRAC + S&amp;P 500) ya existe en
			<a href="https://github.com/cdamken/gbm-dashboard" target="_blank" rel="noopener" style="color: var(--blue);">gbm-dashboard</a>
			y se va a portar a este app en la próxima entrega.
			<br><br>
			Si la necesitas urgente, puedes correr el dashboard local con
			<code>./dashboard.sh</code> desde tu Mac.
		</p>
	</div>

	<div class="disclaimer">
		Dashboard no oficial — datos vía
		<a href="https://github.com/cdamken/gbm-mx-api" target="_blank" rel="noopener">gbm-mx-api</a>.
		No afiliado con Grupo Bursátil Mexicano.
	</div>
</div>
