# GBM P0 — Portafolio que reconcilia — Design

- **Date:** 2026-07-09
- **Status:** Approved (design); ready for writing-plans
- **Repo:** `gbm-owncloud` (app `gbm`, v0.20.2) — primary app (trio dissolved 2026-07-06; built here in PHP).
- **Tracks:** P0 in `docs/superpowers/specs/2026-07-03-gbm-app-audit-and-redesign-roadmap.md`; GitHub epic #15.

## Goal

Make the Portafolio landing KPIs **reconcile and mean one thing**, computed
**server-side in PHP from one source**. Fixes the three trust bugs the owner
found (value < cost yet P&L positive; header P&L ≠ sum of account cards; the
$664 header/cards gap) and the misleading XIRR "no converge". Layout stays
essentially the same — this is correctness + a clear money model, **not** the
P1 visual redesign.

## The design north star (from the audit)

> **Valor de mercado = aportaciones netas + P&L realizado + P&L no realizado − comisiones**

Two rules: **never present "costo" as "aportaciones"** (cost basis of current
holdings ≠ lifetime contributions), and **label every % with its method**.

## Data reality (all present — NO schema change)

- **`gbm_holdings`** (`HoldingMapper::findByUser`): `getAccountId()` (int FK to
  the account row), `getSecurityId()`, `getQuantity()`, `getAvgCost()`,
  `getMarketValue()`. → cost basis, market value, and **per-account grouping**.
- **`gbm_accounts`** (`AccountMapper::findByUser`): `getId()`, `getAccountKey()`
  (EP47NC0x), `getName()`, `getCashAmount()`, `getTotalValue()`. → cash + account
  labels; join `Holding.accountId → Account.id`.
- **`gbm_transactions`** (`TransactionMapper::findByUser`): `getType()` (GBM
  category), `getAmount()`, `getBookedAt()`. Net contributions come from
  **external** flows only — `external_deposit` / `external_withdrawal` (internal
  `deposit`/`withdrawal` = traspasos between the user's own sub-accounts, which
  net to zero at portfolio level and are NOT contributions). Dated external
  flows also feed XIRR.
- **Income** — dividends net of ISR + interest, from the fiscal classification
  already built for F (`FiscalReport`/`gbm_dividends`).

## Architecture (M1–M3 pattern)

### Pure compute — `lib/Analytics/`

- **`PortfolioReconcile`** (new, pure): builds the money model from arrays the
  service loads. `build(array $holdings, array $accounts, float $netContrib, float $income): array`
  returning the labeled lines + per-account breakdown (below). Unit-tested.
- **`Xirr`** (new, pure): `compute(array $flows): ?float` where each flow is
  `['date'=>string,'amount'=>float]` (negative = money in, positive = value out
  / terminal). Newton-Raphson + bisection fallback, ported from the JS in
  `dashboard.js:218-256` into PHP. Returns `null` when <2 flows or all same-sign.
  Unit-tested against a known-rate fixture.

### Service + endpoint

- A thin service (extend `AnalysisService` or a new `PortfolioService`) loads
  holdings/accounts/transactions/dividends (per-user, `$uid` from session),
  computes net contributions + income, and calls the pure core.
- **New endpoint `GET /api/summary`** (route `api#summary`, `@NoAdminRequired`,
  `@NoCSRFRequired` — read-only), returning the authoritative money model as
  JSON. This is the **single source** for the landing's headline + account cards.

### Front (minimal)

- `js/dashboard.js` fetches `/api/summary` and **renders those values** into the
  existing KPI cards and per-account cards, replacing the client-side KPI math
  (`accountValue`/`accountPnL`/the `investmentsGroups`-vs-sum logic). The
  positions **table** stays as-is for P0 (per-position detail; its numbers come
  from the same ingested data). No new CSS; layout unchanged.

## The money model (JSON contract from `/api/summary`)

```
{
  "market_value":     float,   // Σ holdings market_value
  "cost_basis":       float,   // Σ avgCost × qty of CURRENT holdings
  "unrealized_pl":    float,   // market_value − cost_basis
  "unrealized_pct":   float,   // cost_basis>0 ? unrealized_pl/cost_basis*100 : 0
  "cash":             float,   // Σ accounts.cash_amount (shown separately)
  "total_value":      float,   // market_value + cash
  "net_contributions":float,   // external_deposit − external_withdrawal
  "income_net":       float,   // dividends + interest − withholding (net ISR)
  "realized_pl":      null,    // DEFERRED — "próximamente" (needs full lot coverage)
  "xirr":             float|null,          // money-weighted; null when it can't converge
  "xirr_status":      "ok"|"insufficient_flows",
  "positions_count":  int,
  "accounts": [
    { "key": string, "name": string, "value": float, "unrealized_pl": float }
  ]
}
```

## The three bugs — resolved by construction

- **#2 (header P&L ≠ account cards):** per-account `unrealized_pl` is computed
  **only** from holdings grouped by `accountId` (one source). Accounts with no
  holdings → `unrealized_pl` = 0 (never the API's `plus_minus`). Header
  `unrealized_pl` = Σ of the per-account values, exactly.
- **#1 (value < cost, P&L positive):** the landing no longer subtracts
  incompatible bases. `cost_basis` (of current holdings) and `net_contributions`
  are distinct, labeled lines; `cash` is shown separately; `unrealized_pct` is
  over `cost_basis`. The master identity is displayed as the mental model.
- **#3 ($664 gap):** header total and account cards both come from `/api/summary`
  (one source) → they sum identically. No `investmentsGroups`-vs-sum divergence.

## Returns, labeled by method

- **XIRR — "Rendimiento personal (money-weighted)"** — dated external flows +
  the current `total_value` as the terminal positive flow. Converges now that
  real flows are supplied. Fallback: show "—" with "faltan flujos" when
  `xirr_status = insufficient_flows` (honest, replaces "no converge").
- **P&L no realizado %** — labeled as such (not a generic "Rendimiento").

## Error handling / edge cases

- No holdings / empty account → zeros, not errors; account with only cash shows
  cash, `unrealized_pl` 0.
- Negative cash (T+2 settlement) is shown as-is in the `cash` line (it is real);
  the master identity still holds because cash is its own line, not folded into
  P&L.
- XIRR non-convergence → `null` + `insufficient_flows` status; UI shows "—".
- Money parsed to float at the service edge (exact-string columns).

## Testing

- **`tests/php/test_portfolio_reconcile.php`** — a fixture of holdings across 2
  accounts (+ one $0-holding account) + cash + contributions + income →
  assert each money line, that per-account `unrealized_pl` sums to the header,
  that a $0-holding account contributes 0, and the identity relationships.
- **`tests/php/test_xirr.php`** — a known cash-flow set with a hand-computed
  rate (e.g. −1000 at t0, +1100 at t0+1yr → ~10%); same-sign flows → null;
  <2 flows → null.
- **Gates:** `verify_dom_ids.py` / `verify_wiring.py` (new endpoint wiring in
  `dashboard.js`), `python3 -m unittest discover -s tests`.
- **Authoritative:** PHP core suite on the server 7.4.3; then load the
  Portafolio page and confirm: header P&L = Σ account cards; value/cost/cash
  read as distinct labeled lines; XIRR shows a real % (not "—"/"no converge");
  positions count matches.

## Global constraints (inherited)

- **PHP 7.4.3 target**; **no schema change** (all data present); **CSP strict**;
  **per-user isolation** (`$uid` from `IUserSession` via `GbmService`, never from
  input); money exact-string → float at the edge; **compute in PHP** (this is
  the migration of the KPI math off `js/dashboard.js`); UI Spanish / code English;
  commit + push + deploy via `scripts/deploy.sh`.

## Out of scope (explicit)

Realized P&L (deferred — needs full FIFO lot coverage incl. funds); the P1 visual
redesign (declutter/hero/progressive disclosure); TWR + period returns (data-gated,
#13); migrating the positions **table** to the endpoint (P0 keeps it client-side);
three-way capital/dividend/FX return (P2).
