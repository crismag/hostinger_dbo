# Demo TicketDesk 4a — Readiness Checklist

Tracks the gateway-side prerequisites for building the **Demo TicketDesk 4a** app. This phase ("4a Readiness Hardening") closed the gaps the demo would immediately expose; the demo app itself is **not** built yet.

## Readiness

| # | Item | Status | Evidence |
| --- | --- | --- | --- |
| 1 | SQLite install works through **CLI** | ✅ | `bin/install.php` with `DB_DRIVER=sqlite` / `--app`; verified end-to-end |
| 2 | SQLite install works through **web** | ✅ | `public/install.php` driver selector → SQLite; verified via the browser flow (DB file created `0600`) |
| 3 | App **manifest** can initialize demo entities | ✅ | `bin/install.php --app app.json` **and** the web installer's manifest field register entities from `data/registry.json` |
| 4 | Demo **client** can be created | ✅ | Installer creates a scoped client; manifest install grants the app's entities |
| 5 | Service handler **scope helper** exists | ✅ | `ServiceContext::scopedWhere()` / `bindScopedWhere()` / `enforceScopeOrFail()`; `TenantSummary` uses it |
| 6 | **No tenant leakage** in service results | ✅ | `service_registry_smoke` proves a scoped client never sees a foreign tenant |
| 7 | **Smoke tests** pass | ✅ | gateway, hardening, query_features, driver_matrix (sqlite+mysql), service_registry |
| 8 | **CI** exists | ✅ | `.github/workflows/ci.yml` — PHP 8.1–8.4, lint + smoke (SQLite always; MySQL skips cleanly) |
| 9 | **No HMAC secret reaches the browser** | ✅ (by design) | Secret lives in `config/security.php`; the demo's BFF signs server-side. The web installer shows a created secret **once** and never re-displays it |

## Security controls confirmed this phase

- Web installer: **driver choice (mysql/sqlite)**, SQLite path **must be outside `public/`** (rejected otherwise), `pdo_sqlite` checked, storage dir created, **DB file `0600`** (and WAL/SHM sidecars), **CSRF preserved**, **self-locks** after install, **secret shown once**.
- Service handlers: tenant scope is now a one-call helper; conflicts raise `TENANT_SCOPE_VIOLATION`; covered by tests.

## How the demo will use this (4a)

1. Ship `apps/demo-ticketdesk/app.json` + `data/{schema.sql,seed.sql,registry.json}`.
2. Install with `bin/install.php --app apps/demo-ticketdesk/app.json` (or the web installer's manifest field) under the `demo` profile (`APP_ENV=demo`).
3. The demo backend (BFF) holds the `*-app` client secret and **signs** requests; the browser only talks to the same-origin BFF.
4. Reports/dashboards (4b) go through service operations (e.g. `reports.tenant_summary`).

## Not in scope (build later)

- The Demo TicketDesk app itself (4a) and its UI.
- Demo 4b reports.
- Admin/setup page (Phase 2).
- Packagist publishing, broad service-operation UI.

## Remaining blockers for Demo 4a

**None on the gateway side.** Demo 4a is unblocked. Optional, non-blocking follow-ups: add more reference service operations (incl. a transactional example) as 4b needs them; document the SQLite write-concurrency ceiling for high-write demos.
