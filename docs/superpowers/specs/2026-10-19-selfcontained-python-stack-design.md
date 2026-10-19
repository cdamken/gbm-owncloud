# Self-contained Python stack per ownCloud app

**Date:** 2026-10-19
**Status:** design — awaiting Carlos's approval
**Scope:** gbm (pilot) → trade_republic → scalable_capital
**Coordinates with:** the s5→s6 cutover session (owns `CUTOVER_RUNBOOK.md`)

## Intent / why

Carlos considers it a design error that each app's Python stack lives in
`/opt`. He wants every app to carry inside its own ownCloud app folder
everything it needs, so that migrating or copying an app is self-contained.
This is also pre-work for the s5→s6 cutover: **none** of the `/opt` pieces
exist on s6 yet, and s6 runs a different Python (3.14 vs the 3.11 Carlos
compiled on s5), so the stack must be **rebuilt on s6, not copied**.

## Current state (verified 19.10.2026 on s5 + s6)

Each app depends on four things outside its folder:

1. `/opt/python-3.11/` — base interpreter Carlos compiled because s5's
   system Python is 3.8 (the libs require >=3.10).
2. `/opt/{gbm,tr,sc}-venv/` — the venvs (40M / 188M / 90M), each with a
   **compiled binary** inside: `pydantic_core` ships
   `_pydantic_core.cpython-311-x86_64-linux-gnu.so`.
3. `/opt/{gbm-mx,tr,sc}-api-src/` — source mirrors of the Python libs.
4. `config:system` key `*.python_bin` (`gbm.python_bin`,
   `trade_republic.python_bin`, `scalable_capital.python_bin`) → points PHP
   at `/opt/<app>-venv/bin/python`. PHP calls it via `proc_open` with `HOME`
   set to the user's datadir profile and `PATH` inherited.

TR also needs **Playwright/Chromium** (641M on s5: chromium-1223,
chromium_headless_shell-1223, ffmpeg-1011) via the env var
`PLAYWRIGHT_BROWSERS_PATH`, defaulting to `/var/cache/tr-playwright`. GBM
and SC do not use Playwright.

**Already shared, NOT migrated (verified by the cutover session):** all
session/credential files and data JSON live in the ownCloud datadirectory
`/mnt/owncloud/data/<uid>/<app>/`, which is NFS from 10.1.10.112 and is
**already mounted on s6**. The apps do **not** use the OS keyring
(keyring/SecretStorage are only transitive deps; no user has
`~/.local/share/keyrings`). So credentials and data need no migration work.

## The hard constraint that sets the shape

A venv is **not copyable**: `pyvenv.cfg` and script shebangs bake absolute
paths, and `_pydantic_core*.so` is compiled for one CPython version + arch.
s6 is Python 3.14 (and possibly different arch). Therefore physically
stuffing a prebuilt venv into the app folder would **not** achieve
"copy one folder and run" — on s6 that venv cannot start.

Conclusion: self-containment means **all the _source of truth_ lives in the
app folder; the venv is a build artifact reconstructed in place per host**.

## Design

### Per-app layout (nothing app-specific in `/opt`)

```
apps/<app>/python/
  fetch_wrapper.py          # existing entrypoint
  lib-src/                  # vendored source of the api lib (gbm-mx-api / tr-api / sc-api)
  requirements.lock         # fully pinned deps (incl. the api lib, installed from ./lib-src)
  bootstrap.sh              # builds the env in place; idempotent
  .venv/                    # BUILD ARTIFACT — rebuilt per host, gitignored, never copied
  .playwright/              # TR only: PLAYWRIGHT_BROWSERS_PATH target (build artifact, gitignored)
```

- **`bootstrap.sh`** does, on whatever host it runs:
  `python3 -m venv .venv` → `.venv/bin/pip install -r requirements.lock`
  (the lock installs the api lib from `./lib-src`). For TR it then runs
  `PLAYWRIGHT_BROWSERS_PATH=./.playwright .venv/bin/playwright install
  chromium`. It chowns to `www-data`. Idempotent: safe to re-run.
- **PHP locates python by relative path**, not by `config:system`:
  `GbmService` / `TrService` / `ScalableService` resolve
  `realpath(__DIR__ . '/../../python/.venv/bin/python')`. The existing
  `*.python_bin` config key stays supported as an **override** (fallback
  chain: config value if set → relative `.venv` → `python3`), so there is a
  safe rollback and no flag-day.
- **TR's `PLAYWRIGHT_BROWSERS_PATH`** is set by the PHP service to the
  app-folder `.playwright/` (absolute path resolved at runtime), replacing
  the `/var/cache/tr-playwright` default.

### Interpreter decision

Use the **system Python 3.14 on s6** (Carlos's explicit instruction: put it
in the new version, do not recompile 3.11). The base interpreter is the one
thing that stays outside the app folder — it is a platform prerequisite like
Apache or PHP, not part of the app, and it is not vendored per-app.
`bootstrap.sh` calls `python3` from PATH.

**Risk status (spike on s6, 19.10.2026):** GBM deps **pass clean** on system
Python 3.14.4 — `pip` pulls an official prebuilt wheel
`pydantic_core-2.50.1-cp314-...manylinux2014_x86_64.whl` (no Rust toolchain
needed); resolved httpx 0.28.1 + pydantic 2.14.1, import + runtime OK. The
pydantic/Rust hard risk is **eliminated** for GBM.

**TR + SC also pass clean on 3.14.4** (spike 19.10.2026, nothing compiles
from source, all wheels):
- TR: playwright 1.64.0, cryptography 50.0.2 (cp311-abi3, works on 3.14 via
  abi3), cffi 2.1.1 (cp314), greenlet 3.5.6, websockets 17.2, requests
  2.34.2. imports + `playwright install --dry-run chromium` OK.
- SC: curl_cffi 0.16.3 (cp310-abi3), cryptography 50.0.2, cffi 2.1.1. imports
  OK.

So the binary risk is cleared for all three apps; the system 3.14 interpreter
is confirmed viable. No compiled-3.11 fallback needed.

**TR Chromium build:** playwright 1.64 wants chromium-headless-shell
**v1248** (Chrome 156), NOT the v1223 cached on s5. `bootstrap.sh` for TR
must fetch it fresh into the app-folder `.playwright/`; the s5 cache is not
reusable. Therefore `requirements.lock` must pin playwright **and** its
browser build so bootstrap is reproducible.

### Deploy / migration flow

- `scripts/deploy.sh` changes: drop `/opt/<app>-venv` and
  `/opt/<app>-api-src`; rsync the lib source into `apps/<app>/python/lib-src`;
  run `bootstrap.sh` on the server over SSH instead of
  `pip install ... /opt/...`; remove the `*.python_bin` system-config write
  (or set it to the relative path). The 3-pillar model collapses: app + lib
  are now one tree; the "cache bump" pillar is unchanged.
- **Migration/copy = copy the app folder (source only) + run
  `bootstrap.sh`** on the target host with its own `python3`. The `.venv/`
  and `.playwright/` artifacts are gitignored and rebuilt, never copied.

## Goals

- Zero app-specific content in `/opt`.
- One self-contained tree per app; copy-folder + bootstrap reproduces the
  runtime on any host with a compatible `python3`.
- No change to credentials/data (already on shared NFS).
- Safe rollback via the retained `*.python_bin` override.

## Non-goals / anti-goals

- **Not** making the venv itself copyable (impossible with binary wheels +
  version/arch differences; rebuild-in-place is the correct model).
- **Not** vendoring the Python interpreter or Chromium into git (they are
  large platform binaries fetched by bootstrap, not source).
- **Not** touching the cutover session's work (occ maintenance, DB repro, IP
  swap .101→.111, cert, apache, occ upgrade, activation).
- Failure = any flow (fetch/update/revoke, TR Playwright login) that works
  today on s5 breaks after the restructure, or migration still needs a
  manual `/opt` step.

## Rollout order

1. **GBM first** (no Playwright, smallest, 40M) — validate the whole pattern
   end-to-end on s5, then on s6.
2. **SC** (same shape, no Playwright).
3. **TR last** (adds the Playwright/Chromium piece).

Each step: restructure repo → `bootstrap.sh` on server → verify a live
update run → then next app.

## Left to the builder

- Exact `requirements.lock` generation (pip-compile vs `pip freeze` of a
  clean build) — builder's call, must be fully pinned and include the api lib
  from `./lib-src`.
- Whether `lib-src` is a vendored copy or a git submodule of the api repo —
  builder's call; vendored copy is simpler and matches current rsync.
- `.gitignore` entries for `.venv/` and `.playwright/`.
