# Changelog

All notable changes to **php-dbo-gateway** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/crismag/php-dbo-gateway/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/crismag/php-dbo-gateway/releases/tag/v0.1.0
