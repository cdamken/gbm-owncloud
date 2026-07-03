# GBM App — Audit & Redesign Roadmap

- **Date:** 2026-07-03
- **Status:** Audit + roadmap (umbrella). Each P-item below becomes its own spec→plan→build cycle.
- **Repo:** `gbm-owncloud` (app `gbm`, v0.20.1) — but most trust issues live **upstream** in `gbm-dashboard`.
- **Method:** Code-level audit (KPI computation traced to `file:line`) + UX/IA audit of all pages + benchmark of comparable apps (Sharesight, Empower/Personal Capital, Trade Republic, Bloomberg PORT). No live-browser access — findings are from the source + owner screenshots. Benchmark tear-sheet artifact: `https://claude.ai/code/artifact/82300ddc-732d-47c3-bb61-0f4295f399aa`.
- **Trigger:** Owner spotted that the Portafolio numbers don't reconcile (value < cost yet P&L positive).

## The one insight that drives everything

The app conflates several distinct "P&L" concepts on one screen, using **incompatible bases** and **two data sources**. Every trust bug below is a symptom. The fix is a single, strictly-separated **reconciling money model**, anchored on this identity:

> **Valor de mercado = aportaciones netas + P&L realizado + P&L no realizado − comisiones**

Unrealized P&L is *blind* to withdrawals, realized results, and reinvested dividends — so "market value < everything I deposited, yet unrealized P&L is positive" is **not** a contradiction; it was just mislabeled. Two rules follow, and they are the north star of the redesign:

1. **Never use "costo" as a synonym for "aportaciones"** on a summary screen. Cost basis of *current holdings* ≠ lifetime contributions (selling a lot removes its cost).
2. **Label every % with its method** — never a bare "Rendimiento: X%".

---

## Dimension 1 — Confianza (numbers must reconcile) — HIGHEST PRIORITY

All three live in the **upstream** `gbm-dashboard` (verbatim-ported `js/dashboard.js` + `templates/main.php`). Per the trio rule, fixes land upstream first, then port here.

| # | Symptom (from real screenshot) | Verdict | Root (file:line) |
|---|---|---|---|
| 1 | Valor total $887k **<** Costo invertido $958k, yet P&L **+$28k** | **Labeling bug** (numbers right, bases incompatible) | `Costo` = Σ `average_cost` of holdings, no cash (`js/dashboard.js:346`); `Valor` = market value **incl. cash** (`:340-342`, `accountValue()` `:165-184`). Cash ≈ −$71k (T+2 settlement). Presented as subtractable. |
| 2 | Header P&L (+$28,441.72) = only accounts *with* positions; cards show P&L on $0-value accounts (Smart Cash +$74.81, Asesor +$8,606.90) | **Definition bug (HIGH)** — reconciliation breaks | Header = Σ `yield_value` of `positionsFlat` (`:345`, excludes empty accounts). Empty-account cards fall back to `account.plus_minus` from the GBM API (historical/intraday) (`:189-195`). Two sources. |
| 3 | Valor total $887,363.72 ≠ Σ account cards $886,699.70 (**$664**) | **Data-source split** | Header uses `investmentsGroups.total_position.amount` when present (`:340-342`); cards sum `accountValue()` per account (`:461`). Money-market/pending aggregated differently. |
| 4 | XIRR "no converge" | **Misleading label** | XIRR *is* implemented (Newton-Raphson + bisection, `:218-256`). "no converge" almost always = <2 flows or same-sign flows (no deposit/withdrawal data), not solver failure. Label should say "faltan flujos". |

**Corroboration:** Trade Republic shipped this exact class of bug (performance dropped while all holdings rose, because their number blended realized gains + fees) → major user backlash. The pro answer is strict separation.

### The fix — the "reconciling money model" (design north star)

Show **seven labeled money lines**, each with one unambiguous definition:

- **Aportaciones netas** = depósitos − retiros (needs `gbm_cash_flows` — see #13; today derivable from ledger deposit/withdrawal rows).
- **Costo base de posiciones actuales** = lo pagado por los lotes que *aún tienes* (incl. comisiones; NO aportaciones de por vida).
- **Valor de mercado** = precio × cantidad de posiciones abiertas (+ efectivo, mostrado por separado).
- **P&L no realizado** = valor de mercado − costo base actual.
- **P&L realizado** = ingresos de ventas − costo de lotes vendidos (base gravable).
- **Ingresos** = dividendos/intereses netos de ISR.
- **P&L total** = (realizado + no realizado) + ingresos.

Return figures, each labeled with its method:
- **MWR / XIRR** = *tu experiencia real* → the **primary** personal headline. (Correction to the earlier roadmap, which put TWR first — Sharesight/Fidelity lead with money-weighted.)
- **TWR** = neutraliza el timing de aportes → *secundario*, para comparar vs benchmark. (Fidelity naming: "Personal (money-weighted)" vs "Investment (time-weighted)".)

---

## Dimension 2 — Claridad / UX-IA

High-impact findings (full per-page audit in the session record):

- **Portafolio landing overloaded** — 7 KPI cards + concentration banner + account chips + top movers + full positions table, no hierarchy. Pro apps lead with **one** hero number + progressive disclosure (Empower). Positions table renders all rows (50+), no default sort by weight, no debounce.
- **Redundant, conflicting "top movers"** — Portafolio (price-only, client-side aggregation) vs Análisis Ganadores/perdedores (total-return incl. dividends, server-side). Different rankings, different sources → user can't tell which is "true". Consolidate to one source.
- **Navigation confusion** — "Movimientos" vs "Órdenes" vs "Histórico" (two are near-duplicates). Rename/group (e.g. "Órdenes realizadas" vs "Histórico de órdenes"). 7-tab flat bar; account chips don't drill down (should filter/navigate).
- **Inconsistency across pages** — staleness chip only on some pages; empty states vary (Portafolio "—", Análisis prose, Dividendos hidden); 4 different filter UX patterns; timestamp formats differ.
- **Fiscal discoverability** — Dividendos has no per-tax-year aggregation; the "Generar reporte fiscal" button is buried in Settings.
- **In-page explanation** — key concepts (XIRR, yield-on-cost, cost-basis trajectory, benchmark rebasing) rely on tooltips/glossary; the "¿Le gano al mercado?" caption ("mismo ritmo de aportes") is opaque.

## Dimension 3 — Capacidades (steal-this, ranked by impact/effort)

1. **Seven labeled money lines** (above) — *Crítico / Barato.*
2. **Label every return with its method** — *Crítico / Barato.*
3. **Allocation por clase Y por moneda** (MXN vs USD-vía-SIC) — *Alto / Barato* (extends M2).
4. **Descomposición de retorno en 3: capital / dividendo / tipo de cambio** (Sharesight's best idea; answers "¿ganó la acción o el peso?" for SIC/USA) — *Alto / Medio.*
5. **Dividendos por evento: bruto / ISR / neto / tipo de cambio**, plegado en el retorno de la posición — *Alto / Medio.*
6. **XIRR headline + TWR secundario**, con naming explícito — *Alto / Medio* (data-gated by snapshots).
7. **Drill-down por posición** con su libro completo + marcadores de eventos corporativos — *Alto / Medio.*
8. **Hero number + verde/rojo consistente**, todo suma de vuelta a él — *Alto / Barato.*
9. **Benchmark con flujos emparejados** (S&P 500 para SIC, IPC para MX) + "le ganas por X%" — *Medio-alto / Medio* (M3 base ya existe).
10. **Max drawdown / underwater chart** desde NAV — *Medio / Barato* (M4).
11. **Contribution-to-return por posición** (peso × retorno) — *Medio / Medio.*
12. Sharpe / beta / atribución Brinson completa — *Bajo / Pesado — omitir* (sin decisión que cambie para 2 usuarios).

---

## Prioritized roadmap

Each item becomes its own brainstorm→spec→plan→build cycle (like M1–F).

### P0 — Confianza (do first)
Redesign the Portafolio KPIs around the reconciling money model: seven labeled money lines; separate cash; P&L% over current cost basis; **unify per-account P&L to one source**; fix the header/cards $664 split; fix the XIRR label. **Upstream in `gbm-dashboard` first, then port.** This is the single highest-impact change — if the landing number contradicts itself, nothing else matters.

### P1 — Claridad
Lighter landing (one hero + reconciliation, progressive disclosure); consolidate "top movers" to one source; rename/group navigation; staleness chip + consistent empty states everywhere; surface fiscal (per-year aggregation on Dividendos, button more discoverable). Mixed upstream/here.

### P2 — Capacidades
Per-holding drill-down; three-way return (capital/dividend/FX); allocation by currency; cumulative fees/ISR; then the data-gated items (MWR/TWR + period returns + `gbm_cash_flows`, max drawdown) as snapshot history accrues — already tracked in GitHub #13.

## Inherited constraints

- **Trio rule:** trust/dashboard fixes originate **upstream** (`gbm-dashboard`), then port verbatim here. Only multi-user-forced changes originate here.
- **PHP 7.4.3** on the server; **additive schema only**; **CSP strict**; **per-user isolation**; UI Spanish / code English; every change committed + pushed + deployed via `scripts/deploy.sh`.
- **Data-gated:** TWR / period returns / risk need accumulated daily-snapshot history (grows per 🔄 Actualizar) — GitHub #13.
- Fiscal figures are estimates; GBM's *constancia fiscal* is authoritative.

## Next step

Recommended: start P0 with a focused brainstorm→spec of the **reconciling money model** for the Portafolio landing (the seven money lines + return naming), authored **upstream in `gbm-dashboard`** and then ported. Everything else hangs off getting the numbers to reconcile first.
