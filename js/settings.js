/**
 * GBM Portfolio — Settings page logic.
 * Per-user credentials, days config, session revoke.
 */
(function () {
	'use strict';

	let routes;
	let configured = false;  // set by loadSessionInfo — are creds already saved?
	const $ = (id) => document.getElementById(id);

	function csrfHeaders() {
		const t = (typeof OC !== 'undefined' && OC.requestToken) || '';
		return {
			'Content-Type': 'application/json',
			'requesttoken': t,
		};
	}

	function flash(el, ok, msg) {
		if (!el) return;  // callers pass $(id) which may be null
		el.textContent = msg;
		el.className = 'flash show ' + (ok ? 'ok' : 'err');
		// Make the feedback impossible to miss: pull it into view (it can sit
		// below the fold on the long settings page) and let the success banner
		// linger long enough to read before it auto-hides.
		try { el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (_) {}
		if (ok) setTimeout(() => el.classList.remove('show'), 6000);
	}

	async function loadAll() {
		// Account
		try {
			const r = await fetch(routes.config, { cache: 'no-store' });
			const c = await r.json();
			if (c.email) $('email-input').value = c.email;
		} catch (_) {}

		// Days + versions
		try {
			const r = await fetch(routes.settingsApi, { cache: 'no-store' });
			const s = await r.json();
			$('orders-days-input').value       = s.orders_days != null ? s.orders_days : 90;
			$('dividends-days-input').value    = s.dividends_days != null ? s.dividends_days : 365;
			$('transactions-days-input').value = s.transactions_days != null ? s.transactions_days : 365;
			$('about-app-version').textContent = s.app_version || '—';
			$('about-api-version').textContent = s.gbm_mx_api_version || '—';
		} catch (_) {}

		await loadSessionInfo();
	}

	async function loadSessionInfo() {
		const ul = $('session-info');
		try {
			const r = await fetch(routes.config, { cache: 'no-store' });
			const c = await r.json();
			configured = !!c.configured;
			ul.innerHTML = c.configured
				? '<li><span class="label">Credenciales</span><span class="value green">guardadas (' + c.email + ')</span></li>'
				+ '<li><span class="label">TOTP</span><span class="value muted">solo cuando expire el refresh token (~30 días)</span></li>'
				: '<li><span class="label">Credenciales</span><span class="value red">sin configurar</span></li>';
		} catch (_) {
			ul.innerHTML = '<li><span class="label">Estado</span><span class="value red">no se pudo consultar</span></li>';
		}
	}

	async function saveAccount() {
		const email    = $('email-input').value.trim();
		const password = $('password-input').value;
		const flashEl  = $('account-flash');
		const btn      = $('save-account-btn');

		if (!email || !email.includes('@')) { flash(flashEl, false, 'Email inválido.'); return; }
		if (!password) {
			// Credenciales ya guardadas + campo de contraseña vacío: por
			// seguridad nunca rellenamos la contraseña, así que esto NO es un
			// error — solo no hay nada nuevo que guardar. Explícalo en vez de
			// gritar "Contraseña muy corta".
			flash(flashEl, configured,
				configured
					? 'Tus credenciales ya están guardadas. Escribe una contraseña solo si quieres cambiarla.'
					: 'Escribe tu contraseña de GBM+ para guardar.');
			return;
		}
		if (password.length < 4) { flash(flashEl, false, 'Contraseña muy corta (mínimo 4 caracteres).'); return; }

		btn.disabled = true; btn.textContent = 'Guardando…';
		try {
			const r = await fetch(routes.config, {
				method: 'POST',
				headers: csrfHeaders(),
				body: JSON.stringify({ email, password }),
			});
			const p = await r.json();
			if (r.ok && p.status === 'ok') {
				flash(flashEl, true, 'Credenciales guardadas.');
				$('password-input').value = '';
				await loadSessionInfo();
			} else {
				flash(flashEl, false, p.detail || 'No se pudo guardar.');
			}
		} catch (e) {
			flash(flashEl, false, 'Error de conexión: ' + e.message);
		} finally {
			btn.disabled = false; btn.textContent = 'Guardar credenciales';
		}
	}

	async function saveData() {
		const flashEl = $('data-flash');
		const btn     = $('save-data-btn');
		const payload = {
			orders_days:       parseInt($('orders-days-input').value, 10),
			dividends_days:    parseInt($('dividends-days-input').value, 10),
			transactions_days: parseInt($('transactions-days-input').value, 10),
		};
		for (const k of Object.keys(payload)) {
			if (!Number.isFinite(payload[k]) || payload[k] < 1 || payload[k] > 3650) {
				flash(flashEl, false, 'Cada valor debe estar entre 1 y 3650 días.');
				return;
			}
		}
		btn.disabled = true; btn.textContent = 'Guardando…';
		try {
			const r = await fetch(routes.settingsApi, {
				method: 'POST',
				headers: csrfHeaders(),
				body: JSON.stringify(payload),
			});
			const p = await r.json();
			if (r.ok && p.status === 'ok') {
				flash(flashEl, true, 'Rangos guardados. Aplican al próximo ⟳ Actualizar.');
			} else {
				flash(flashEl, false, p.detail || 'No se pudo guardar.');
			}
		} catch (e) {
			flash(flashEl, false, 'Error de conexión: ' + e.message);
		} finally {
			btn.disabled = false; btn.textContent = 'Guardar rangos';
		}
	}

	async function revokeSession() {
		if (!confirm('Esto borra la sesión local y todos los datos descargados. El próximo ⟳ Actualizar pedirá TOTP.\n\n¿Continuar?')) return;
		const flashEl = $('session-flash');
		const btn = $('revoke-session-btn');
		btn.disabled = true;
		try {
			const r = await fetch(routes.reset, {
				method: 'POST',
				headers: csrfHeaders(),
				body: '{}',
			});
			const p = await r.json();
			if (r.ok && p.status === 'ok') {
				const msg = p.signed_out_globally
					? 'Sesión revocada en Cognito (refresh_token inválido server-side) + caché borrado.'
					: 'Caché borrado localmente. Cognito GlobalSignOut no se ejecutó'
					  + (p.signout_detail ? ' (' + p.signout_detail + ')' : '') + '.';
				flash(flashEl, !!p.signed_out_globally, msg);
				await loadSessionInfo();
			} else {
				flash(flashEl, false, p.detail || 'No se pudo revocar.');
			}
		} catch (e) {
			flash(flashEl, false, 'Error de conexión: ' + e.message);
		} finally {
			btn.disabled = false;
		}
	}

	function switchAccount() {
		if (!confirm('Vas a conectarte a otra cuenta GBM+.\n\nLos campos email y contraseña se vaciarán. ¿Continuar?')) return;
		$('email-input').value = '';
		$('password-input').value = '';
		const f = $('account-flash');
		f.textContent = 'Captura el email y contraseña de la otra cuenta GBM+, después dale Guardar.';
		f.className = 'flash show ok';
		$('email-input').focus();
	}

	function wireSidebarScrollspy() {
		const links = document.querySelectorAll('.settings-side a');
		const sections = Array.from(links).map(a => $(a.dataset.section)).filter(Boolean);
		const observer = new IntersectionObserver((entries) => {
			for (const e of entries) {
				if (!e.isIntersecting) continue;
				links.forEach(a => a.classList.toggle('active', a.dataset.section === e.target.id));
			}
		}, { rootMargin: '-30% 0px -60% 0px', threshold: 0 });
		for (const s of sections) observer.observe(s);
	}

	document.addEventListener('DOMContentLoaded', () => {
		const root = $('gbm-app');
		document.body.classList.add('gbm-app-active');
		routes = {
			config:      root.dataset.routeConfig,
			settingsApi: root.dataset.routeSettingsApi,
			reset:       root.dataset.routeReset,
		};

		$('save-account-btn').addEventListener('click', saveAccount);
		$('switch-account-btn').addEventListener('click', switchAccount);
		$('save-data-btn').addEventListener('click', saveData);
		$('revoke-session-btn').addEventListener('click', revokeSession);
		$('password-input').addEventListener('keydown', (e) => {
			if (e.key === 'Enter') saveAccount();
		});

		loadAll();
		wireSidebarScrollspy();
	});
})();
