# gbm-owncloud

App para **ownCloud 10** que da a cada usuario su propio dashboard del portafolio
de **GBM+** (Grupo Bursátil Mexicano). Las credenciales y los datos viven
aislados por usuario dentro del propio ownCloud.

> ⚠️ **No oficial.** No está afiliada, endorsed ni patrocinada por Grupo Bursátil
> Mexicano. Hecha por ingeniería inversa de la app web. Los endpoints pueden
> cambiar sin aviso. Usar bajo tu propio riesgo.

---

## Qué hace

- Aparece como un app más en la barra de navegación de ownCloud, junto a Files,
  Calendar, etc.
- Cada usuario:
  - Configura una vez su email + password de GBM+ desde la propia app
    (modal **⚙ Cuenta**). La password se cifra antes de guardarse.
  - Verifica 2FA con TOTP la primera vez (después la sesión se reusa ~1h).
  - Descarga sus posiciones (BMV, SIC, fondos, extranjero), cuentas (Trading
    MX, Trading USA, Smart Cash) y órdenes llenas de los últimos N días.
  - Renderiza un dashboard oscuro con resumen, top movers, tabla buscable
    + ordenable de posiciones, y una página de movimientos con desglose por
    mes y por emisora.
- **Aislamiento por usuario garantizado por construcción** — ver
  [ARCHITECTURE.md](ARCHITECTURE.md).

## Diferencia con `gbm-dashboard`

| | [gbm-dashboard](https://github.com/cdamken/gbm-dashboard) | **gbm-owncloud (este repo)** |
|---|---|---|
| Forma | Script local Python + browser en localhost | App de ownCloud multi-usuario |
| Quién la ejecuta | Tú en tu Mac | Tu instancia de ownCloud |
| Datos por usuario | N/A (un solo usuario) | Sí, aislados en `{datadir}/<uid>/gbm/` |
| Credenciales | `.env` con `0600` en tu home | DB de ownCloud, password cifrada con `ICrypto` |
| Acceso remoto | No (solo localhost) | Sí (vía URL de tu ownCloud, con su login y 2FA) |
| Auto-actualización | Manual con `./dashboard.sh` | Botón ⟳ Update en el header |

Si solo quieres verlo tú en tu máquina, usa `gbm-dashboard`. Si quieres que
varios usuarios de tu ownCloud lo tengan, este es el repo.

## Dependencias

- **ownCloud 10.x**.
- **Python 3.10+** en el server.
- **[`gbm-mx-api`](https://github.com/cdamken/gbm-mx-api)** instalado en ese
  Python (un venv dedicado funciona perfecto).

Ver [INSTALL.md](INSTALL.md) para los pasos exactos, incluyendo qué hacer si
tu server está en Ubuntu 20.04 (donde el Python del sistema es 3.8 y deadsnakes
ya no publica para `focal`).

## Instalación corta

```bash
# 1. Venv con la lib Python (asume python3.10+ disponible)
sudo python3 -m venv /opt/gbm-venv
sudo /opt/gbm-venv/bin/pip install gbm-mx-api      # o desde fuente: pip install /path/to/gbm-mx-api

# 2. Clonar el app a la carpeta de apps de ownCloud
cd /var/www/owncloud/apps
sudo -u www-data git clone https://github.com/cdamken/gbm-owncloud.git gbm

# 3. Habilitar y apuntar al venv
sudo -u www-data php /var/www/owncloud/occ app:enable gbm
sudo -u www-data php /var/www/owncloud/occ config:system:set gbm.python_bin --value=/opt/gbm-venv/bin/python
sudo -u www-data php /var/www/owncloud/occ config:system:set gbm.orders_days --value=90
```

Listo — cada usuario abre `https://tu-owncloud/index.php/apps/gbm/`, mete sus
credenciales en el modal, valida TOTP, y ya está.

## Uso

1. **Primera vez** — al entrar al app aparece el modal **⚙ Cuenta** pidiendo
   tu email + password de GBM+.
2. **Al guardar** — se dispara automáticamente una sincronización. Como aún
   no hay sesión, se abre el modal **🔐 Código de seguridad** pidiendo el
   TOTP de 6 dígitos.
3. **Tecleas el TOTP** — se hace login, se descargan tus 4 cuentas, sus
   posiciones (todas las secciones), y las órdenes llenas de los últimos 90
   días. Aparece el dashboard.
4. **Update** — el botón **⟳ Update** del header rebaja datos. Si la sesión
   sigue viva (~1h) no pide TOTP; si expiró, vuelve a pedirlo.
5. **Cambiar credenciales** — el botón **⚙ Cuenta** reabre el modal.

## Configuración

Los valores se setean en el `config.php` del server via `occ`:

| Clave system        | Default     | Para qué |
|---------------------|-------------|----------|
| `gbm.python_bin`    | `python3`   | Ruta al Python con `gbm-mx-api` instalado. |
| `gbm.orders_days`   | `90`        | Cuántos días hacia atrás bajar de órdenes llenas. |

```bash
sudo -u www-data php occ config:system:set gbm.python_bin --value=/opt/gbm-venv/bin/python
sudo -u www-data php occ config:system:set gbm.orders_days --value=180
```

## Dónde se guarda cada cosa

| Dato | Lugar |
|---|---|
| Email de GBM (por usuario) | DB de ownCloud (`oc_preferences`) |
| Password de GBM (por usuario, **cifrada**) | DB de ownCloud (`oc_preferences`), cifrada con `ICrypto` |
| Sesión 2FA `session.json` | Filesystem: `{datadir}/<uid>/gbm/session.json` (`0600`) |
| Posiciones / cuentas / órdenes (JSON) | Filesystem: `{datadir}/<uid>/gbm/*.json` |
| `fetch.log` del último run | Filesystem: `{datadir}/<uid>/gbm/fetch.log` |
| `gbm.python_bin`, `gbm.orders_days` | `config.php` de ownCloud |

Detalle completo y razones en [ARCHITECTURE.md](ARCHITECTURE.md).

## Desinstalar (limpio)

```bash
sudo -u www-data php occ app:disable gbm
# por cada usuario que la haya usado:
sudo -u www-data php occ user:setting <uid> gbm --delete
sudo rm -rf {datadir}/<uid>/gbm/
```

## Estado

Alpha. Funciona en mi propio ownCloud (Ubuntu 20.04 + Apache + PHP-FPM +
Python 3.11.15 standalone + `gbm-mx-api 0.1.2`). Si lo pruebas y rompe algo,
abre un [issue](https://github.com/cdamken/gbm-owncloud/issues).

## Licencia

[Business Source License 1.1](LICENSE) — alineada con `gbm-mx-api` y
`gbm-dashboard`. Convierte a Apache 2.0 a los 4 años. Si quieres usarla en
producción comercial antes de eso, escríbeme.

## Créditos

- API GBM+ → [`gbm-mx-api`](https://github.com/cdamken/gbm-mx-api).
- Dashboard original (versión local) → [`gbm-dashboard`](https://github.com/cdamken/gbm-dashboard).
- App de ownCloud (este repo) → Carlos Damken.
- Inspiración estructural → apps `pong` y `drawio` de ownCloud.
