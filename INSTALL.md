# INSTALL — gbm-owncloud

Guía paso a paso para dejar la app corriendo en un servidor de ownCloud 10.
Asumo Ubuntu/Debian, Apache + PHP-FPM. Para otras combos los conceptos son
los mismos.

## 0. Pre-requisitos

- ownCloud 10.x ya corriendo.
- Usuario root (`sudo`) en el server.
- El usuario web del ownCloud (`www-data`, `nginx`, etc.) — los comandos
  asumen `www-data`.

```bash
# Verifica versión de ownCloud
sudo -u www-data php /var/www/owncloud/occ -V

# Saca dónde está el datadirectory (lo vas a necesitar)
sudo -u www-data php /var/www/owncloud/occ config:system:get datadirectory
```

## 1. Python 3.10+ en el server

### Caso A — Ya tienes Python 3.10+ instalado

Verifícalo:

```bash
python3 --version    # tiene que decir 3.10+
```

Si sí, salta a la sección 2.

### Caso B — Ubuntu 22.04 / 24.04 / Debian 12+ con apt

```bash
sudo apt update
sudo apt install -y python3 python3-venv python3-pip
python3 --version
```

### Caso C — Ubuntu 20.04 (focal) — el caso difícil

Ubuntu 20.04 viene con Python 3.8 y `gbm-mx-api` exige 3.10+. **deadsnakes
PPA ya no publica para focal**, así que apt no es opción.

La solución limpia es usar [python-build-standalone](https://github.com/astral-sh/python-build-standalone),
un build portable de CPython publicado por Astral. Se descomprime en `/opt/`
y no toca nada del sistema.

```bash
# 1. Encuentra el último release y descarga el tarball x86_64-linux
TAG=$(curl -fsSL https://api.github.com/repos/astral-sh/python-build-standalone/releases/latest \
        | grep -m1 '"tag_name"' | sed 's/.*"\([0-9]*\)".*/\1/')
URL=$(curl -fsSL "https://api.github.com/repos/astral-sh/python-build-standalone/releases/tags/$TAG" \
        | grep browser_download_url \
        | grep cpython-3.11 \
        | grep x86_64-unknown-linux-gnu \
        | grep install_only.tar.gz \
        | grep -v stripped \
        | head -1 | sed 's/.*"\(https[^"]*\)".*/\1/')
echo "Bajando: $URL"

# 2. Extrae a /opt/python-3.11/
cd /tmp && curl -fsSL -o python311.tar.gz "$URL"
sudo mkdir -p /opt/python-3.11
sudo tar -xzf python311.tar.gz --strip-components=1 -C /opt/python-3.11

# 3. Verifica
/opt/python-3.11/bin/python3 --version    # Python 3.11.x
```

A partir de aquí, donde estos pasos digan `python3`, sustituye por
`/opt/python-3.11/bin/python3`.

## 2. Venv con `gbm-mx-api`

```bash
sudo /opt/python-3.11/bin/python3 -m venv /opt/gbm-venv
sudo /opt/gbm-venv/bin/pip install --upgrade pip
sudo /opt/gbm-venv/bin/pip install gbm-mx-api

# Si gbm-mx-api aún no está en PyPI, instala desde fuente:
#   git clone https://github.com/cdamken/gbm-mx-api.git /tmp/gbm-mx-api
#   sudo /opt/gbm-venv/bin/pip install /tmp/gbm-mx-api

# Verifica
/opt/gbm-venv/bin/python -c "from gbm_mx_api import GbmClient; print('OK')"

# El web user tiene que poder ejecutarlo
sudo chown -R www-data:www-data /opt/gbm-venv
```

## 3. Instalar el app

```bash
cd /var/www/owncloud/apps
sudo -u www-data git clone https://github.com/cdamken/gbm-owncloud.git gbm
```

> **Importante:** la carpeta del app **tiene que llamarse `gbm`** (no
> `gbm-owncloud`), porque ese es el `id` declarado en `appinfo/info.xml`.
> ownCloud busca el app por id, no por nombre de carpeta arbitrario.

Permisos:

```bash
sudo chown -R www-data:www-data /var/www/owncloud/apps/gbm
sudo chmod -R u+rX,g+rX,o-rwx /var/www/owncloud/apps/gbm
```

Habilítalo:

```bash
sudo -u www-data php /var/www/owncloud/occ app:enable gbm
sudo -u www-data php /var/www/owncloud/occ app:list | grep gbm
```

## 4. Apuntar el app al venv

```bash
sudo -u www-data php /var/www/owncloud/occ config:system:set gbm.python_bin \
    --value=/opt/gbm-venv/bin/python
sudo -u www-data php /var/www/owncloud/occ config:system:set gbm.orders_days \
    --value=90
```

## 5. Verifica

```bash
# Smoke test del wrapper como el web user
sudo -u www-data /opt/gbm-venv/bin/python \
    /var/www/owncloud/apps/gbm/python/fetch_wrapper.py --help
```

Tiene que imprimir el usage. Si truena con "ModuleNotFoundError: gbm_mx_api"
quiere decir que el venv está bien apuntado pero la lib no está instalada
en *ese* venv.

## 6. Probar en el browser

Abre `https://tu-owncloud/index.php/apps/gbm/` logueado.

Flujo esperado:

1. Aparece modal "⚙ Configuración de cuenta GBM+".
2. Metes email + password → click Guardar.
3. Aparece modal "🔐 Código de seguridad".
4. Tecleas TOTP de 6 dígitos → click Actualizar.
5. Se descarga todo y aparece el dashboard.

## Troubleshooting

### "CSRF check failed" en el GET inicial

Versión vieja del app sin `@NoCSRFRequired`. Asegúrate de tener el HEAD
de `main` o un tag `v0.1.1+`.

### 500 al abrir la app, sin nada en `owncloud.log`

El error está en el log de Apache/PHP-FPM, no en el de ownCloud:

```bash
sudo tail -n 80 /var/log/apache2/error.log | grep -i -E 'gbm|fatal|undefined'
# o
sudo tail -n 80 /var/log/php*-fpm.log
```

### Update se queda en "Actualizando..." indefinidamente

```bash
# Mira el último run
sudo cat /mnt/owncloud/data/<uid>/gbm/fetch.log
```

El timeout interno del wrapper es 180s. Si la API de GBM tarda más, sube
`GBM_ORDERS_DAYS` (menos días = menos tiempo) o aumenta el timeout en
`lib/Service/GbmService.php` (constante `runProcess(...180)`).

### "MFA_REQUIRED" infinito (no acepta el TOTP)

Revisa la hora del server — TOTP es time-based, si tu reloj se desvió
más de 30s del de Google el código nunca va a coincidir:

```bash
timedatectl
sudo timedatectl set-ntp true
```

### Quiero ver qué credenciales tiene guardadas un usuario

```bash
sudo -u www-data php /var/www/owncloud/occ user:setting <uid> gbm
```

(El `password_enc` aparece cifrado — eso es lo correcto.)

### Quiero borrar todo lo de un usuario

```bash
sudo -u www-data php /var/www/owncloud/occ user:setting <uid> gbm --delete
sudo rm -rf /mnt/owncloud/data/<uid>/gbm/
```

### Actualizar el app

```bash
cd /var/www/owncloud/apps/gbm
sudo -u www-data git pull
sudo -u www-data php /var/www/owncloud/occ maintenance:repair
```

(`maintenance:repair` no es estrictamente necesario para esta app porque
no toca la DB, pero no estorba.)
