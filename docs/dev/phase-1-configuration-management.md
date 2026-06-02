# Phase 1 — Application Configuration Foundation

**Status:** Planned · **Depends on:** none · **Target version:** `v0.3.0`
**Includes:** Phase 1.5 — Application Definition Framework (folded into this milestone)

## Goal

Establish the configuration foundation everything else depends on: make the storage backend a choice (`mysql` | `sqlite`), introduce `dev`/`demo`/`prod` profiles, add a declarative **application-definition model**, make the installer driver-aware, and turn SQLite into a first-class deployment target — all without touching the gateway's data/query/security logic, which is already driver-agnostic.

**Milestone deliverables:** (1) multi-driver configuration, (2) profiles, (3) app-definition model, (4) driver-aware installer, (5) SQLite deployment support.

> This is the milestone where the project stops looking like "a PHP database gateway" and starts looking like a deployable application data-service platform: apps become *defined* rather than hand-wired.

## Current state (context)

- **Logic is portable.** [`tests/gateway_smoke.php`](../../tests/gateway_smoke.php) and [`tests/hardening_smoke.php`](../../tests/hardening_smoke.php) run the full pipeline (auth, nonce, rate-limit, permissions, CRUD, audit) on `sqlite::memory:` and pass. The new LIKE/GROUP BY/aggregate code was verified on SQLite too.
- **Only the plumbing is MySQL-bound:**
  - [`src/Database/Connection.php`](../../src/Database/Connection.php) builds a hardcoded `mysql:host=…` DSN from `config/database.php` (`host, port, database, username, password, charset`).
  - [`schema/security_tables.sql`](../../schema/security_tables.sql) and [`schema/example_objects.sql`](../../schema/example_objects.sql) use MySQL-only DDL.
  - [`src/Install/Installer.php`](../../src/Install/Installer.php) uses `SHOW TABLES`, `CREATE DATABASE … `, and `ON DUPLICATE KEY UPDATE`.
- **Config loading today:** `config/database.example.php` reads `.env` + `getenv()` and returns a flat array; precedence is env over file. `config/security.php` is a separate returned array.

## Scope

**In scope**
- `driver` selection and per-driver connection settings.
- SQLite DSN / file-path management (location, permissions, auto-create).
- `dev`/`demo`/`prod` profiles selecting config overrides.
- Driver-specific schema files and installer dialect support.
- SQLite test fixtures + a driver-compatibility test harness.

**Out of scope**
- Any change to QueryBuilder/RequestValidator/repository/middleware (already portable).
- Postgres or other drivers (design should not preclude them, but not implemented).
- Admin UI (Phase 2).

## Design decisions

**DECISION 1 — Driver config shape.** Extend `config/database.php` with a `driver` key and per-driver settings, keeping the existing MySQL keys working (backward compatible).

```php
return [
    'driver' => getenv('DB_DRIVER') ?: 'mysql',   // 'mysql' | 'sqlite'
    'mysql'  => [ 'host'=>…, 'port'=>…, 'database'=>…, 'username'=>…, 'password'=>…, 'charset'=>'utf8mb4' ],
    'sqlite' => [ 'path' => getenv('DB_SQLITE_PATH') ?: dirname(__DIR__).'/storage/gateway.sqlite' ],
];
```
*Backward compat:* if `driver` is absent, treat top-level `host/database/…` as the mysql block (current behaviour). Recommended.

**DECISION 2 — Profile selection.** Use `APP_ENV` (`dev`|`demo`|`prod`, default `prod`) to select a profile, implemented as a **base config + per-profile overrides**, not three full files. Profiles set policy knobs (`require_https`, `dev_mode`, `audit.mode`, `public_demo.enabled`, default `driver`). Fits the existing env-var support. *Open question:* override mechanism — a `config/profiles.php` map vs. `config/{env}.php` overlay files. Recommend a single `config/profiles.php` returning `['dev'=>[…],'demo'=>[…],'prod'=>[…]]` merged over the base.

**DECISION 3 — SQLite schema files.** Add `schema/sqlite/security_tables.sql` and `schema/sqlite/example_objects.sql`. Store `schema_json` as plain JSON **string literals** (SQLite has no `JSON_OBJECT`; JSON1 functions exist but literals are simpler and identical at rest). The installer picks the schema directory by driver.

### MySQL → SQLite DDL mapping (reference for the schema variants)

| MySQL | SQLite |
| --- | --- |
| `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | `INTEGER PRIMARY KEY AUTOINCREMENT` |
| `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` | *(omit)* |
| `ENUM('a','b')` | `TEXT` + optional `CHECK(col IN ('a','b'))` |
| `TINYINT(1)` | `INTEGER` |
| `JSON` | `TEXT` |
| `JSON_OBJECT(...)` seed | inline JSON string literal |
| `TIMESTAMP … ON UPDATE CURRENT_TIMESTAMP` | `TEXT`/`DATETIME DEFAULT CURRENT_TIMESTAMP` (drop `ON UPDATE`; app sets `updated_at`, or a trigger) |
| `UNIQUE KEY name (a,b)` | `UNIQUE(a,b)` |

## Application Definition Framework (Phase 1.5)

A small, declarative manifest that becomes the single source of truth for standing up an application on the gateway. It seeds configuration, the installer, admin (Phase 4), and every demo app — so apps stop reinventing their own setup.

```json
{
  "app": "ticketdesk",
  "driver": "sqlite",
  "database": "storage/ticketdesk.sqlite",
  "entities": ["tickets", "customers", "agents"],
  "services": ["reports", "ticketing"]
}
```

**DECISION 4 — Manifest is an orchestration *index*, not a god-file.** It lists entity **names** and service **names** only. The full entity policies (`fields`/`insertable`/`searchable`/`groupable`/`aggregatable`, etc.) live in **referenced per-entity definitions** (e.g. `apps/<app>/data/registry.json`, one policy object per entity), and service names map to the `OperationRegistry` allowlist (Phase 3). This keeps the manifest small and stable, and keeps entity policy where it belongs (the registry). Recommended.

- **Location:** `apps/<app>/app.json` (per app); the gateway records installed apps so admin can list/manage them.
- **Validation:** every entity name must resolve to a valid policy (constructs an `EntitySchema` without error); every service name must resolve in the `OperationRegistry`; `driver` ∈ {mysql, sqlite}; `database` path must be under `storage/` for sqlite. Invalid manifests are rejected before any side effect.
- **Scope guard:** MVP only. **Defer** the "app marketplace" framing until the primitive proves itself; the manifest is plumbing, not a runtime.

**Consumers**

| Consumer | Uses the manifest to… |
| --- | --- |
| Configuration | pick `driver` + `database`/DSN |
| Installer | one-shot setup: create DB, load schema, register the listed entities (from their policy files), grant a client the listed services |
| Admin (Phase 4) | list installed apps; add/disable entities/services declared by an app |
| Demo apps | each app ships its own `app.json`; `demo-ticketdesk` is the first |

**Implementation (this milestone)**

- `src/Config/AppDefinition.php` — load + validate a manifest; expose `driver()`, `database()`, `entities()`, `services()`.
- Installer integration — `bin/install.php --app path/to/app.json` runs the full setup from the manifest (driver/schema/entities/client/service-grants) non-interactively.
- A per-entity policy loader that reads `registry.json` and writes `api_entities` rows (reusing `EntitySchema` validation).

## Implementation plan

1. **`src/Database/Connection.php`** — read `driver`; build `mysql:` or `sqlite:` DSN. For SQLite: `new PDO('sqlite:'.$path)`, then `PRAGMA foreign_keys=ON`, `PRAGMA journal_mode=WAL`, `PRAGMA busy_timeout=5000`. Keep the existing options (`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`).
2. **`config/database.example.php`** — new shape (DECISION 1); document `DB_DRIVER`, `DB_SQLITE_PATH`.
3. **`config/profiles.php`** (new) + small profile-merge in the config loader / `public/index.php` bootstrap (DECISION 2).
4. **`schema/sqlite/*.sql`** (new) — ported DDL per the mapping table; keep table/column names identical so the registry and QueryBuilder are unchanged.
5. **`src/Install/Installer.php`** — driver-aware:
   - `connect()` / DSN by driver; SQLite has no `CREATE DATABASE` (ensure parent dir exists + file perms `0600`).
   - `existingTables()` → `SELECT name FROM sqlite_master WHERE type='table'` for SQLite.
   - schema path → `schema/` vs `schema/sqlite/` by driver.
   - `createClient()` upsert → replace `ON DUPLICATE KEY UPDATE` with a portable path (SELECT-then-update, or `INSERT … ON CONFLICT(client_id,entity_name) DO UPDATE` which both MySQL 8 and SQLite support; verify on the MySQL version matrix, else branch).
   - preflight: add a writable-`storage/` + SQLite-extension check.
6. **`bin/harden-permissions.sh`** — include the SQLite DB file (`0600`) and ensure it lives under `storage/` (never docroot).
7. **Tests:**
   - Promote the inline SQLite `CREATE TABLE`s in the smoke tests into a shared `tests/fixtures/` (loaded from `schema/sqlite/*.sql` once they exist, to keep them honest).
   - **Driver-compat test** (`tests/driver_matrix_smoke.php`): run the core CRUD + query-control assertions against **both** `sqlite::memory:` and (when reachable) the configured MySQL, asserting identical results.

## Security considerations

- SQLite file **must** live outside the web root (default `storage/gateway.sqlite`) at `0600`; `storage/` is already `0700` and gitignored.
- `prod` profile defaults: `require_https=true`, `dev_mode=false`, `public_demo.enabled=false`, `audit.mode=authenticated_only`. `demo` profile may enable `public_demo`; `dev` may set `dev_mode`.
- WAL mode creates `-wal`/`-shm` sidecar files — keep them in `storage/`, not servable.
- No secrets move into SQLite; HMAC secrets stay in `config/security.php`/env.

## Test plan / Definition of Done

- [ ] `Connection` opens both drivers from config; `PRAGMA`s applied for SQLite.
- [ ] `schema/sqlite/*.sql` import cleanly; registry rows readable; example entities resolve.
- [ ] Installer completes a full SQLite install (schema, client, config, perms, lock) non-interactively and via web.
- [ ] `gateway_smoke`, `hardening_smoke`, `query_features_smoke` pass against **both** drivers.
- [ ] `driver_matrix_smoke` shows parity (CRUD, LIKE, GROUP BY, aggregates) across mysql + sqlite.
- [ ] Profiles select expected policy knobs; `prod` is fail-secure by default.
- [ ] `AppDefinition` loads + validates a manifest; rejects unknown entities/services, bad driver, and out-of-`storage/` sqlite paths.
- [ ] `bin/install.php --app app.json` stands up an app end-to-end (driver, schema, entities from policy files, client, service grants) non-interactively.
- [ ] Docs updated: `installation.md`, `deployment.md`, `database-schema.md` gain a SQLite path and an app-definition section; CHANGELOG `v0.3.0`.

## Unblocks

Phase 4a (a clickable SQLite CRUD/query demo) becomes buildable immediately after this phase.
