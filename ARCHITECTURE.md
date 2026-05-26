# ARCHITECTURE — gbm-owncloud

Cómo está construida la app, dónde vive cada cosa y por qué.

## Diagrama de alto nivel

```
┌────────────────────────────────────────────────────────────────────────┐
│  Browser del usuario (logueado en ownCloud, su propia sesión + cookie) │
└──────────────────────────────┬─────────────────────────────────────────┘
                               │ HTTPS
                               ▼
┌────────────────────────────────────────────────────────────────────────┐
│  ownCloud 10  (Apache + PHP-FPM)                                       │
│                                                                        │
│  Router → OCA\Gbm\Controller\PageController     (GET  /)               │
│        → OCA\Gbm\Controller\ApiController       (GET/POST  /api/...)   │
│                                                                        │
│  CSRF middleware activo en los POST (setConfig, update)                │
│                                                                        │
│  PageController/ApiController                                          │
│    └─ DI: OCA\Gbm\Service\GbmService                                   │
│         └─ ctor recibe IUserSession->getUser()->getUID()  ← AQUÍ está  │
│            atada la identidad. No hay otro userId en todo el código.   │
│                                                                        │
│  GbmService.runFetch($totpCode)                                        │
│    └─ proc_open([                                                      │
│           gbm.python_bin,                                              │
│           apps/gbm/python/fetch_wrapper.py,                            │
│           --session-path  {datadir}/<uid>/gbm/session.json,            │
│           --data-dir      {datadir}/<uid>/gbm/,                        │
│           --totp          (si lo mandó el browser)                     │
│       ], env=GBM_EMAIL, GBM_PASSWORD (descifrada via ICrypto), ...)    │
└──────────────────────────────┬─────────────────────────────────────────┘
                               │ subprocess
                               ▼
┌────────────────────────────────────────────────────────────────────────┐
│  fetch_wrapper.py  (Python 3.10+, venv con gbm-mx-api)                 │
│                                                                        │
│   GbmClient.from_saved(session_path) ─ reusa sesión si vive            │
│   GbmClient.login(email, password, totp_provider, persist_to=...) si no│
│                                                                        │
│   ► contracts.get_main()                                               │
│   ► accounts.list()                                                    │
│   ► positions.summary(account)  × cada cuenta                          │
│   ► orders.list_filled(account, from_date, to_date)                    │
│                                                                        │
│   Escribe:  {datadir}/<uid>/gbm/accounts.json                          │
│             {datadir}/<uid>/gbm/positions.json                         │
│             {datadir}/<uid>/gbm/orders.json                            │
│             {datadir}/<uid>/gbm/last_update.date                       │
└──────────────────────────────┬─────────────────────────────────────────┘
                               │ HTTPS
                               ▼
                         GBM+ public API
                  (Cognito + bursanetapp.gbm.com.mx)
```

## Layout en disco (por usuario)

```
{datadirectory}/<uid>/gbm/
├── session.json       ← persisted by gbm-mx-api; 0600
├── accounts.json      ← list of accounts + their P&L
├── positions.json     ← {account_legacy_id: position_summary}
├── orders.json        ← {from_date, to_date, account, orders: [...]}
├── last_update.date   ← "2026-05-20 22:31:05"
└── fetch.log          ← stdout/stderr del último run (debug)
```

`{datadirectory}` viene de `occ config:system:get datadirectory`. En mi
server es `/mnt/owncloud/data`.

Todo se crea con `0700` para el directorio y `0600` para los archivos. El
único proceso que necesita leerlos es el `www-data` que corre ownCloud,
y el Python que lanza también corre como `www-data`.

## Aislamiento por usuario — el modelo

El usuario `alice` no puede ver los datos de `bob`. Garantizado así:

1. **Identidad atada en construcción.** En `lib/Application.php`:

   ```php
   $container->registerService(GbmService::class, function (IAppContainer $c) {
       $user = $c->query(IUserSession::class)->getUser();
       if ($user === null) {
           throw new \RuntimeException('GBM app: no user in session');
       }
       return new GbmService($user->getUID(), ...);
   });
   ```

   `GbmService` solo se construye con el `userId` del usuario *de la sesión
   actual*. No hay otra forma de crear el servicio. Los controllers solo
   reciben este `GbmService` (vía DI), nunca un `userId` arbitrario.

2. **Paths derivados del userId.** Cualquier ruta de archivo dentro del
   servicio se construye así:

   ```php
   $this->dataDirRoot . '/' . $this->userId . '/gbm/...'
   ```

   Pasar otro `userId` requeriría romper la inmutabilidad del campo
   (`private $userId`), lo cual no se puede sin patchear el código.

3. **Whitelist en `dataPath()`.** El método que mapea un nombre de archivo a
   ruta filtra con whitelist explícita (`accounts.json`, `positions.json`,
   `orders.json`, `last_update.date`). Path traversal (`../`) no funciona.

4. **CSRF activo en endpoints de mutación.** `setConfig` y `update` validan
   el token de ownCloud. Otro tab/dominio no puede dispararlos sin la
   cookie de sesión del usuario.

5. **`@NoAdminRequired` no significa "público"**. La anotación dice "no
   requiere ser admin", pero el middleware de auth de ownCloud sigue
   exigiendo login. Sin login no hay user → el binding de DI lanza
   `RuntimeException` y la request muere.

## Credenciales — dónde y cómo

| Campo | Forma | Tabla / Path | Cifrado |
|---|---|---|---|
| Email | string | DB `oc_preferences` (`<uid>`, `gbm`, `email`) | No (es público en GBM) |
| Password | string | DB `oc_preferences` (`<uid>`, `gbm`, `password_enc`) | **Sí**, `ICrypto::encrypt` |
| Sesión Cognito | JSON | Filesystem `{datadir}/<uid>/gbm/session.json` (0600) | No (es de corta vida, ~1h) |

`ICrypto::encrypt` de ownCloud usa AES-256-CBC con el `secret` definido en
`config.php`. Sin acceso al `config.php` del server, las passwords cifradas
en `oc_preferences` no se pueden recuperar.

## Por qué la sesión va en disco y no en DB

`gbm-mx-api` ya tiene un mecanismo limpio para persistir sesiones a un path
en disco. Usar lo mismo:

- Evita reescribir esa lógica en PHP.
- Mantiene la sesión cerca de los JSON de datos (mismo dir).
- Es el contrato natural de la lib (`--session-path` ya existe en mi
  wrapper).

Las sesiones expiran en ~1h y se reescriben transparentemente. Si te
preocupa la persistencia en disco, puedes mover esto a Redis o a la DB en
una versión futura — el cambio es local a `fetch_wrapper.py` + `GbmService`.

## Por qué los datos JSON viven en `appdata`-like y no en `files/`

Tres razones:

1. **No queremos que aparezcan en el File explorer del usuario.** Si los
   pongo en `{datadir}/<uid>/files/GBM/`, los vería en la web y se le
   sincronizarían al desktop client.
2. **Privacidad relativa.** Cualquier mecanismo que enseñe `files/`
   (compartido, link público) podría exponer los JSON. Fuera de `files/`
   no hay forma de listarlos sin acceso al filesystem.
3. **Limpieza explícita.** Cuando un usuario se va, basta con borrar el dir
   `gbm/` — no hay que cazar archivos sueltos dentro de `files/`.

## Por qué bridge Python en lugar de port a PHP

`gbm-mx-api` es la fuente de verdad de los endpoints reales de GBM, y se
mantiene aparte. Cuando GBM cambie algo, `gbm-mx-api` se actualiza y este
app gana la corrección automáticamente con un `pip install -U gbm-mx-api`.

Si en algún momento se justifica un port PHP — porque el shell-out
escala mal, o porque alguien lo quiere publicar al marketplace de
ownCloud sin dependencias externas — se puede hacer manteniendo la misma
interfaz del `GbmService` (los controllers no se enteran).

## Modelo de errores

`fetch_wrapper.py` usa exit codes que `GbmService` mapea a status JSON que
el JS interpreta:

| Exit | JSON status | HTTP | Significado |
|------|------------|------|-------------|
| 0    | `ok`             | 200 | Todo bien |
| 10   | `mfa_required`   | 401 | Sesión expirada o ausente, browser muestra modal TOTP |
| 11   | `mfa_invalid`    | 401 | TOTP equivocado o expirado |
| 12   | `auth_failed`    | 401 | Email/password rechazados por GBM |
| 20   | `api_error`      | 502 | GBM falló o el cliente la API tronó |
| 30   | `config_error`   | 500 | Wrapper no encontrado, lib faltante, env vacío |

El JS del browser tiene una rama explícita para cada uno (abre modal de
TOTP, abre modal de config, etc.).

## Diferencias con la arquitectura de `gbm-dashboard`

`gbm-dashboard` corre un mini HTTP server Python en localhost y sirve
HTML estático + endpoints `/update` y `/config`. Aquí:

- El HTTP server **es ownCloud** — la app no levanta un server propio.
- `/update` y `/config` se vuelven rutas de ownCloud, con auth +
  CSRF + per-user scope reales.
- Las páginas HTML se vuelven templates renderizadas por ownCloud
  (con su layout, navegación, etc.).
- El `fetch_data.py` se vuelve `fetch_wrapper.py` con paths
  parametrizados (los hardcodeados desaparecen).

## Punto de extensión: añadir una nueva vista

Para añadir, p.ej., una página de "alertas":

1. Añadir ruta en `appinfo/routes.php`:
   `['name' => 'page#alerts', 'url' => '/alerts', 'verb' => 'GET']`
2. Añadir método `alerts()` en `PageController` que devuelva un nuevo
   `TemplateResponse` (con `@NoCSRFRequired` para que el browser pueda
   abrirlo).
3. Crear `templates/alerts.php` y `js/alerts.js`.
4. Las URLs de datos siguen viniendo del array `routes` que el template
   inyecta en `window.OC_GBM.routes`.

No se tocan controllers ni servicios.

## Punto de extensión: añadir un nuevo dato a sincronizar

Caso típico: querer guardar también órdenes pendientes/canceladas (no solo
"llenas").

1. Modificar `python/fetch_wrapper.py` para añadir, p.ej.,
   `client.orders.list_for_range(...)` y escribir `orders_all.json`.
2. Añadir `'orders_all.json'` a la whitelist en `GbmService::dataPath()`.
3. Añadir `'orders_all' => 'orders_all.json'` en `ApiController::data()`.
4. Cualquier nueva vista que lo quiera consumir lo pide vía
   `dataUrl('orders_all')`.

`fetch_wrapper.py` es el único punto donde se decide qué se baja y cómo se
estructura — todo lo demás es presentación.
