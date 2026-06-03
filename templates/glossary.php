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
	data-route-data="<?php p($routes['data']); ?>"
	data-route-config="<?php p($routes['config']); ?>"
	data-route-update="<?php p($routes['update']); ?>">

	<div class="subtitle">
		Términos de inversión en México (BMV, SAT, fondos, etc.) y conceptos
		del dashboard. Búsqueda en vivo.
	</div>

	<div class="glossary-controls">
		<input
			type="text"
			id="glossary-search"
			placeholder="Buscar término (ej. ISR, BMV, XIRR, FIBRA)..."
			autocomplete="off">
	</div>

	<div class="no-results" id="no-results">Sin coincidencias.</div>

	<!-- Mercados e instrumentos -->
	<div class="glossary-section" data-section>
		<h2>Mercados e instrumentos</h2>

		<dl class="term">
			<dt>BMV <span class="label">mercado</span></dt>
			<dd>Bolsa Mexicana de Valores. Mercado donde se listan las acciones y
				ETFs mexicanos (ej. <code>WALMEX</code>, <code>FEMSA UBD</code>).
				En el dashboard aparece como bucket <code>mercado_capitales</code>.</dd>
		</dl>

		<dl class="term">
			<dt>SIC <span class="label">mercado</span></dt>
			<dd>Sistema Internacional de Cotizaciones. Permite operar acciones
				extranjeras (principalmente estadounidenses) desde la BMV en pesos
				mexicanos. Los tickers terminan en <code>*</code>
				(ej. <code>NVDA *</code>, <code>AAPL *</code>). Bucket
				<code>mercados_globales_sic</code>.</dd>
		</dl>

		<dl class="term">
			<dt>Trading USA / Mercado extranjero <span class="label">mercado</span></dt>
			<dd>Cuenta GBM que opera directamente en mercados estadounidenses (NYSE,
				Nasdaq) en dólares. Soporta acciones fraccionarias. Bucket
				<code>mercado_extranjero</code>.</dd>
		</dl>

		<dl class="term">
			<dt>FIBRA <span class="label">instrumento</span></dt>
			<dd>Fideicomiso de Inversión en Bienes Raíces. Análogo a los REITs en
				EE.UU. — vehículo que invierte en inmuebles y distribuye al menos
				95% del resultado como dividendos. Cotizan en BMV con sufijos
				numéricos (ej. <code>FUNO 11</code>, <code>FSHOP 13</code>).</dd>
		</dl>

		<dl class="term">
			<dt>Sociedades de Inversión (Fondos) <span class="label">instrumento</span></dt>
			<dd>Fondos mutuos mexicanos. El dashboard distingue dos:
				<code>F. Deuda</code> (fondos de renta fija, bonos, papel comercial)
				y <code>F. Común</code> (mixtos o de renta variable). Bucket
				<code>sociedades_inversion_*</code>.</dd>
		</dl>

		<dl class="term">
			<dt>Repo <span class="label">instrumento</span></dt>
			<dd>Reporto. Compra de un instrumento con pacto de recompra a fecha
				futura — equivalente a un préstamo colateralizado de muy corto
				plazo. En GBM aparecen como <code>repo_buy</code> (entras al repo)
				y <code>repo_mature</code> (vence y te devuelven el efectivo +
				interés).</dd>
		</dl>
	</div>

	<!-- Cuentas GBM+ -->
	<div class="glossary-section" data-section>
		<h2>Cuentas GBM+</h2>

		<dl class="term">
			<dt>Trading MX (Personal) <span class="label">cuenta</span></dt>
			<dd>Cuenta legacy de trading directo en BMV/SIC. En tu contrato
				<code>EP47NCxx</code>, suele ser la cuenta <code>05</code>.
				Permite el "blotter" de órdenes (compra/venta).</dd>
		</dl>

		<dl class="term">
			<dt>Trading USA <span class="label">cuenta</span></dt>
			<dd>Operación directa en bolsas USA en dólares (no SIC). Acciones
				fraccionarias. La API de órdenes de Trading USA es menos confiable
				que la de BMV — el dashboard cae a derivar cost basis del snapshot
				de posiciones cuando falla.</dd>
		</dl>

		<dl class="term">
			<dt>Smart Cash <span class="label">cuenta</span></dt>
			<dd>Cuenta de tesorería automatizada — el efectivo se invierte
				automáticamente en instrumentos de muy corto plazo. Tiene una
				variante en USD (<code>Smart Cash Dólares</code>) que vino con la
				v3 del dashboard endpoint.</dd>
		</dl>

		<dl class="term">
			<dt>Asesor <span class="label">cuenta</span></dt>
			<dd>Cuenta administrada por GBM (estrategia asignada por un asesor
				financiero). El dashboard lista posiciones igual que cualquier
				otra cuenta.</dd>
		</dl>
	</div>

	<!-- Fiscal SAT -->
	<div class="glossary-section" data-section>
		<h2>Fiscal (SAT)</h2>

		<dl class="term">
			<dt>ISR <span class="label">impuesto</span></dt>
			<dd>Impuesto Sobre la Renta. GBM retiene una porción del dividendo
				bruto al SAT (ISR cedular sobre dividendos, típicamente 10%). En
				el dashboard aparece marcado con la pill <code>tax_withholding</code>.
				Suma bruto = neto recibido + ISR retenido.</dd>
		</dl>

		<dl class="term">
			<dt>IVA <span class="label">impuesto</span></dt>
			<dd>Impuesto al Valor Agregado. Se carga sobre las comisiones de
				intermediación (16% en México). El dashboard captura el campo en
				las órdenes (<code>iva</code>) pero rara vez es relevante para análisis de
				P&L — es parte del costo de operación.</dd>
		</dl>

		<dl class="term">
			<dt>ISR cedular sobre dividendos <span class="label">impuesto</span></dt>
			<dd>Retención específica del 10% sobre dividendos recibidos de empresas
				mexicanas. Es definitivo (no acreditable contra ISR anual a partir
				de cierto umbral).</dd>
		</dl>

		<dl class="term">
			<dt>DOF (Diario Oficial de la Federación) <span class="label">referencia</span></dt>
			<dd>Publica el tipo de cambio oficial usado por el SAT para conversiones
				USD/MXN en declaración. El dashboard NO usa este tipo de cambio —
				muestra los valores que GBM mismo reporta (que vienen con su propio
				FX en vivo).</dd>
		</dl>
	</div>

	<!-- Métricas del dashboard -->
	<div class="glossary-section" data-section>
		<h2>Métricas del dashboard</h2>

		<dl class="term">
			<dt>P&L acumulado <span class="label">métrica</span></dt>
			<dd>Yield value histórico que GBM reporta para cada posición
				(<code>yield_value</code> en la API). Es la diferencia entre el
				valor de mercado actual y el costo promedio de adquisición, NO
				incluye dividendos recibidos.</dd>
		</dl>

		<dl class="term">
			<dt>XIRR (Internal Rate of Return) <span class="label">métrica</span></dt>
			<dd>Tasa anualizada money-weighted: considera el momento exacto de
				cada flujo externo (depósitos, retiros) hacia/desde GBM y el valor
				actual del portafolio. Es el "verdadero" rendimiento.<br><br>
				<b>Limitación en GBM</b>: la API solo expone 365 días de
				transacciones, por lo que si depositaste capital antes de esa
				ventana, XIRR no puede reconciliar y muestra "—".</dd>
		</dl>

		<dl class="term">
			<dt>Cost basis trajectory <span class="label">métrica</span></dt>
			<dd>Línea de "Capital invertido en el tiempo" en Análisis. Acumula
				compras − ventas día a día. NO refleja el valor de mercado
				histórico (no tenemos precios pasados) — solo refleja cuándo
				pusiste capital a trabajar.</dd>
		</dl>

		<dl class="term">
			<dt>Forward dividend (proyección 12m) <span class="label">métrica</span></dt>
			<dd>Estimación naive de cuánto vas a recibir de dividendos en los
				próximos 12 meses, escalando lo recibido en la ventana observada
				a 365 días. Requiere ≥90 días de historial para evitar ruido.</dd>
		</dl>

		<dl class="term">
			<dt>Concentración (warning) <span class="label">métrica</span></dt>
			<dd>Banner ámbar si una posición ocupa más del 30% del portafolio
				(o top-5 > 70%); rojo si > 50% (o top-5 > 85%). Heurística pura,
				sin recomendación de qué hacer — solo te avisa que tienes
				exposición concentrada.</dd>
		</dl>
	</div>

	<!-- Categorías del Libro Diario -->
	<div class="glossary-section" data-section>
		<h2>Categorías del Libro Diario</h2>

		<dl class="term">
			<dt>external_deposit / external_withdrawal <span class="label">transacción</span></dt>
			<dd>Movimiento real de dinero ENTRE TU BANCO y GBM (depósito SPEI,
				retiro SPEI), o entre TU GBM y la de otro titular (ej. Felicitas →
				tu Smart Cash). Los únicos que cuentan para flujo externo real —
				los demás <code>deposit</code>/<code>withdrawal</code> son
				<code>TRASPASO</code> entre tus sub-cuentas dentro de GBM.</dd>
		</dl>

		<dl class="term">
			<dt>FX (Foreign Exchange) <span class="label">transacción</span></dt>
			<dd>Conversión USD ↔ MXN dentro de GBM (típicamente Trading USA ↔
				MXN). No cambia el cost basis, no cuenta como flujo externo —
				solo redenomina capital ya invertido.</dd>
		</dl>

		<dl class="term">
			<dt>buy_stock / sell_stock / buy_fund / sell_fund <span class="label">transacción</span></dt>
			<dd>Compras y ventas de acciones (BMV/SIC/USA) o fondos mexicanos.
				Cambian el cost basis pero NO cuentan como flujo externo
				(es capital ya comprometido moviéndose entre instrumentos).</dd>
		</dl>
	</div>

	<div class="disclaimer">
		Dashboard no oficial — datos vía
		<a href="https://github.com/cdamken/gbm-mx-api" target="_blank" rel="noopener">gbm-mx-api</a>.
		No afiliado con Grupo Bursátil Mexicano.
	</div>

</div>
