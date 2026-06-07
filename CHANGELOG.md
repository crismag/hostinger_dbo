# Changelog

All notable changes to **php-dbo-gateway** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Health endpoint** — unauthenticated `GET /health` returns `200 {"status":"ok"}` when the database is reachable and the registry exists, else `503 {"status":"degraded"}`. For load balancers / orchestrators.
- **Security response headers** — `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`, and `Referrer-Policy: no-referrer` on all responses; `Strict-Transport-Security` over HTTPS.
- **Schema migrations** — forward-only, versioned migrations (`schema/migrations/`, `schema/sqlite/migrations/`) recorded in a `schema_migrations` table. Fresh installs auto-apply; existing deployments upgrade with `php bin/migrate.php up`. `App\Install\MigrationRunner`, `tests/migration_smoke.php`. First migration: an `api_audit_logs(ip_address)` index.
- **Community/security files** — `SECURITY.md` (private disclosure), `CONTRIBUTING.md`, issue/PR templates. CI now runs the cross-driver tests against a real **MySQL** service (no longer skipped).
- **Static analysis** — PHPStan (level 6, clean) as a dev dependency with a `phpstan.neon` config and a CI `static-analysis` job; `composer analyse`. Fixed the issues it surfaced (stale `Router` return type, a malformed docblock, an unhandled `match`, redundant runtime checks, and missing array value-type annotations).

- **CLI admin tool** (`bin/admin.php`) — ongoing administration of a running gateway: `status`/`health`, entity enable/disable, client create/enable/disable/revoke, **secret rotation**, and per-entity permission grants. `App\Admin\AdminService` holds the driver-agnostic DB operations; new `Installer::loadSecurity()`/`saveSecurity()` perform incremental, non-clobbering edits to `config/security.php` (re-secured `0600`). Covered by `tests/admin_smoke.php`. Docs: `docs/admin.md`. (Phase 2, CLI-first; the privileged web admin remains opt-in pending an auth-model decision.)

## [0.4.0] - 2026-06-03

### Added

- **Service Registry** — named operations at `POST /api/v1/services/{service}/{operation}` for logic outside the generic gateway (joins, multi-table reports, transactions). Runs behind the same HMAC/nonce/rate-limit/audit pipeline via a new `ServiceController`. Handlers implement `App\Services\Operations\ServiceOperation`, receive a `ServiceContext` (DB + resolved client + enforced scope), and declare an input spec validated before execution. **Handler classes resolve only through a fixed compile-time allowlist** (`OperationRegistry`) — never from config or the database. Per-client grants live under `clients[clientId]['services']`; the `service/operation → key` map is `config/services.php`. Reference operation `reports.tenant_summary` (a JOIN + aggregate). New codes: `SERVICE_NOT_FOUND`, `SERVICE_OPERATION_NOT_FOUND`, `SERVICE_INPUT_INVALID`. Covered by `tests/service_registry_smoke.php`.
- **Web installer parity** — `public/install.php` now supports **SQLite** (driver choice, path validated to be outside `public/`, `pdo_sqlite` check) and **app manifests** (`app.json` → driver/database + entity registration), matching the CLI. The SQLite database file is secured to `0600` (with WAL/SHM sidecars); CSRF, self-lock, and once-only secret display are preserved.
- **Service tenant-scope helper** — `ServiceContext::scopedWhere()` / `bindScopedWhere()` / `enforceScopeOrFail()` make it a one-call step for handlers to honour a client's enforced scope; conflicts raise `TENANT_SCOPE_VIOLATION`. `reports.tenant_summary` uses it; a test proves a scoped client never sees another tenant's rows.
- **CI** — `.github/workflows/ci.yml` runs lint + the smoke suite across PHP 8.1–8.4 (SQLite always; MySQL-dependent suites skip cleanly).
- **Docs** — `docs/service-authoring.md` (how to write service operations safely) and `docs/demo-readiness.md`.
- **Demo app `apps/demo-ticketdesk/`** — a no-framework, SQLite-backed support-ticket app showcasing the gateway end-to-end via a signing BFF (the HMAC secret never reaches the browser). **4a:** CRUD, LIKE search, equality filters, sorting, pagination, and GROUP BY dashboard widgets, with a live request/response panel. **4b:** a JOIN-backed agent-workload report and a transactional "create ticket + first comment", both as service operations (`tickets.agent_workload`, `tickets.create_with_comment`). One-command `setup.php`.
- Empty request bodies (`{}`) are now accepted (some operations take no input); a non-empty JSON array is still rejected as an invalid request object.

## [0.3.0] - 2026-06-02

### Added

- **Multi-driver storage (mysql | sqlite)** — the gateway and installer now support SQLite alongside MySQL/MariaDB, selected via a `driver` key in `config/database.php`. A shared `App\Database\Dsn` factory builds the connection (SQLite gets `foreign_keys`/WAL/`busy_timeout` pragmas). Backward compatible: existing flat MySQL config still works.
- **SQLite deployment support** — driver-specific schema files under `schema/sqlite/`; the installer is driver-aware (`sqlite_master` listing, schema path by driver, no `CREATE DATABASE`, a portable select-then-write permission upsert, and `pdo_sqlite` accepted in preflight). The full pipeline (auth, nonce, rate-limit, CRUD, LIKE, GROUP BY, audit) is verified on both drivers.
- **`config/database.php` shape** — `driver` plus per-driver `mysql`/`sqlite` blocks; new env vars `DB_DRIVER`, `DB_SQLITE_PATH`.
- **Environment profiles** — `config/profiles.php` (`dev`/`demo`/`prod`) deep-merged over `config/security.php`, selected by `APP_ENV`. Opt-in: with no `APP_ENV`, config is used unchanged. `App\Config\Profiles` performs the merge.
- **Application Definition Framework** — a declarative `app.json` manifest (`App\Config\AppDefinition`) naming driver, database, entities, and services. `bin/install.php --app app.json` stands an app up end-to-end: gateway schema, the app's object schema (`data/schema.sql`), entity registration from `data/registry.json` (`Installer::registerEntities`, every identifier validated), and a scoped client. The manifest is an orchestration index; entity policies live in `registry.json`.
- `tests/driver_matrix_smoke.php` — cross-driver parity test (SQLite always; MySQL when reachable).

### Notes

- SQLite database files live under `storage/` (outside the web root, `0600`).
- Backward compatible: MySQL deployments and existing config files are unaffected.

## [0.2.0] - 2026-06-02

### Added

- **LIKE / search filters** — `select` accepts an optional `filters` array of `{field, op, value}` (operators `eq`, `like`), combined with the equality `where` via AND. `like` fields must be in a new registry `searchable` allowlist; values are bound parameters.
- **GROUP BY + aggregates** — `select` performs an aggregate query when `group_by`/`aggregates` are present. Functions: `count`, `sum`, `avg`, `min`, `max`. New registry allowlists `groupable` and `aggregatable`; aliases validated as safe identifiers; `where`/`filters` apply before grouping so tenant scope still constrains aggregates.
- **Response meta** now includes `operation`, `entity`, and `count` alongside `request_id`.
- New validation error codes: `REQUEST_INVALID_OPERATOR`, `REQUEST_FIELD_NOT_SEARCHABLE`, `REQUEST_FIELD_NOT_GROUPABLE`, `REQUEST_FIELD_NOT_AGGREGATABLE`, `REQUEST_INVALID_AGGREGATE`, `REQUEST_INVALID_ALIAS`.
- `tests/query_features_smoke.php` integration test for the new controls.

### Notes

- All additions keep the security model: identifiers are registry-allowlisted, values are parameter-bound, and no raw SQL fragments are accepted. JOINs, multi-table composition, and transactions remain out of scope by design (they belong in named service operations).
- Backward compatible: existing `select/insert/update/delete` requests are unaffected; entities without the new registry keys simply cannot use the new controls.

## [0.1.0] - 2026-06-02

Initial public release of php-dbo-gateway — a secure, dependency-free PHP gateway
for controlled MySQL and MariaDB object access.

### Added

- **Core gateway** — fixed REST routes `POST /api/v1/{entity}/{select|insert|update|delete}`, an explicit middleware pipeline, and a registry-driven object layer (`SchemaRegistry`, `ObjectRepository`, `QueryBuilder`) using PDO prepared statements.
- **HMAC-SHA256 authentication** — request signing over method, path, timestamp, nonce, and body hash, with timing-safe comparison and a configurable timestamp window.
- **Replay protection** — nonce store with an atomic `(client_id, nonce)` uniqueness guard.
- **Rate limiting** — a pre-authentication, filesystem-backed IP limiter (no database reads) plus per-client database-backed limits.
- **Tenant isolation** — server-enforced per-client scope filters (`ScopeEnforcementService`) that callers cannot widen.
- **Mutation guard** — `update`/`delete` must target a primary key unless a client is explicitly exempted.
- **Audit logging** — body-hash (never body-content) request logging with `all`, `authenticated_only`, `sampled`, and `critical_only` modes, plus retention via `bin/cleanup.php`.
- **Registry-driven access control** — per-entity and per-client allowlists for fields, filters, and actions.
- **Optional public demo** — disabled-by-default, read-only, hard-capped anonymous access.
- **Trusted-proxy handling** — `X-Forwarded-For` / `X-Forwarded-Proto` are honoured only from configured `trusted_proxies` (CIDR-aware), preventing client-IP and scheme spoofing.
- **Unified authentication failures** — all signed-auth failures collapse to a single `AUTHENTICATION_FAILED` response to prevent client enumeration.
- **Installer** — shared installer core (`src/Install/Installer.php`) surfaced through a CLI wizard (`bin/install.sh`, `bin/install.php`, interactive and non-interactive) and a self-disabling web installer (`public/install.php`), with environment preflight, schema loading, first-client creation, config generation, and file-permission hardening (`bin/harden-permissions.sh`).
- **Packaging** — `composer.json` (`type: project`, PSR-4 `App\` → `src/`), MIT `LICENSE`, and a Composer-aware front controller that falls back to a built-in autoloader when Composer is absent.
- **Documentation** — README homepage plus guides for installation, security, API reference, architecture, database schema, deployment, and migration.
- **Tests** — `tests/hardening_smoke.php` and `tests/gateway_smoke.php`.

[Unreleased]: https://github.com/crismag/php-dbo-gateway/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/crismag/php-dbo-gateway/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/crismag/php-dbo-gateway/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/crismag/php-dbo-gateway/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/crismag/php-dbo-gateway/releases/tag/v0.1.0
