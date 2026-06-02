# Security Design

## HMAC signing

Every request requires `Content-Type: application/json`, `X-Client-Id`, `X-Timestamp`, `X-Nonce`, and `X-Signature`. Clients calculate a lowercase hexadecimal HMAC-SHA256 signature with their secret over:

```text
METHOD
PATH
TIMESTAMP
NONCE
SHA256_HEX_OF_RAW_BODY
```

The server compares signatures with `hash_equals()`. Timestamp values must use UTC `YYYY-MM-DDTHH:MM:SSZ` syntax and remain within the configured 300-second window.

## Secret storage

`ApiClientResolver` isolates secret lookup. For MVP use, secrets are supplied through `config/security.php`, preferably from environment-backed values. A database fallback exists behind the disabled `allow_database_secrets` flag so storage can be hardened later. If that fallback is enabled, `api_clients.secret_hash` must contain the plaintext HMAC secret value (despite the column name). Do not commit `config/security.php`, log secrets, or store secrets in audit records.

## Transport security

When `require_https` is enabled, `HttpsMiddleware` rejects plaintext HTTP with `HTTPS_REQUIRED` before any other processing. Detection uses `HTTPS`, `SERVER_PORT`, and `REQUEST_SCHEME` directly, and only honours `X-Forwarded-Proto` / `X-Forwarded-For` when the immediate peer is listed in `trusted_proxies`. In that trusted-proxy mode, `Request::fromGlobals()` resolves the client IP from the forwarded chain without trusting direct callers to spoof secure transport. `127.0.0.1`/`::1` and `dev_mode` are exempt for local development.

## Pre-authentication abuse protection

`PreAuthRateLimitMiddleware` runs before any PDO connection is opened, before authentication, and before the audit wrapper. It is keyed by IP and backed by `FilesystemRateLimiter` (local files; no database, Redis, or extension), so invalid clients, bad signatures, and bot scanners are rejected with `RATE_LIMITED` (HTTP `429`) **without** a database read, client lookup, or audit write. It enforces both `minute_limit` and `hour_limit`, using the verified forwarded client IP only when the immediate peer is in `trusted_proxies`. The authenticated per-client `RateLimitService` still applies afterwards.

## Unified authentication failures

Signed-request failures — unknown client, inactive client, disallowed IP, missing/invalid timestamp, malformed signature, or bad signature — all return the single code `AUTHENTICATION_FAILED` (HTTP `401`). The specific internal reason is written to the server error log only, so callers cannot enumerate valid client IDs by comparing error codes or timing.

## Replay protection and rate limiting

After signature validation, `NonceStore` inserts the nonce into `api_nonces`. The `(client_id, nonce)` unique key atomically rejects reuse. `RateLimitService` increments a per-client, per-IP, per-minute bucket in `api_rate_limits` and returns HTTP `429` after the configured threshold.

## Registry and permissions

Enabled entities come from `api_entities`. Registry JSON allowlists selectable, insertable, updatable, filterable, and orderable identifiers. `api_client_permissions` separately restricts each client's actions, visible fields, filter fields, and maximum select size.

## Tenant scope enforcement

Each client may declare server-controlled `enforced_filters` (for example `tenant_id`). `ScopeEnforcementService` merges them into every `where` (and into `data` for insert) after the caller's own fields are authorized. Clients must not send the enforced fields themselves; if a client supplies a conflicting value, the request is either rejected with `TENANT_SCOPE_VIOLATION` (HTTP `403`, the recommended default) or silently overridden, per `tenant_scope.on_violation`. A client can no longer widen its result set with an empty `where`.

## Mutation safety guard

When `mutation_guard` is enabled, `MutationGuardService` requires every `update` and `delete` to filter by the entity's primary key, returning `RESTRICTIVE_WHERE_REQUIRED` (HTTP `422`) otherwise. This prevents broad mutations such as `UPDATE ... WHERE status = 'active'`. A trusted client can be exempted with `allow_bulk_updates`.

## Public demo accessor

`public_demo` is disabled by default. When enabled, unsigned requests are served only for the interfaces explicitly defined in configuration; everything else is denied. `PublicDemoService` is read-only (`select`), hard-caps the row `limit` to `max_limit`, restricts returnable `fields` and `filters` to allowlists, and injects mandatory `required_where` filters the caller cannot remove or override (for example `is_demo = 1`). Demo callers are rate-limited per minute/hour/day by IP using the same filesystem limiter. The demo column used by `required_where` must exist and be registry-filterable.

## Audit logging

`AuditMiddleware` wraps routed requests and writes success or failure metadata to `api_audit_logs`: request ID, resolved client when available, entity, action, method, path, body hash, IP, response status, error code, and duration. Full bodies and secrets are not logged. Audit write failures are logged server-side without replacing the API result.

The volume is controlled by `audit.mode`: `all`, `authenticated_only` (default — only identified clients and demo callers are persisted, so unauthenticated bot traffic never reaches the table), `sampled` (every success plus 1-in-`sample_rate` failures), or `critical_only` (failures only). `bin/cleanup.php` enforces `audit.retention_days` and purges expired nonces, stale rate-limit buckets, and orphaned filesystem counters; run it from cron.

## Why raw SQL is forbidden

Raw SQL would bypass entity allowlists, field permissions, restrictive mutation checks, and identifier validation. The gateway exposes only the four object operations. Values use PDO prepared statements; SQL identifiers must originate from registry allowlists.
