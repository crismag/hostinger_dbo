# Phase 3 — Service Registry

**Status:** Planned · **Depends on:** Phase 1 · **Target version:** `v0.35` (Service Registry MVP)
**Delivery position:** 2nd — built right after the configuration foundation. **This is the architectural bet of the platform:** everything beyond CRUD/LIKE/GROUP BY is pushed to `/serviceName/operation`, so whether this extension model is clean determines the project's future. It is sequenced early to de-risk that unknown before the demo and admin work depend on it. Validated by its own reference handlers + tests (Demo 4a uses no service operations).

## Goal

Provide a structured home for operations the generic gateway deliberately does **not** support — joins, multi-table reports, aggregated dashboards, multi-write transactions — as named `service → operation` handlers, executed behind the same security pipeline, with developer-authored SQL rather than caller-supplied SQL.

## The philosophical resolution (why this is safe)

The gateway forbids **caller-supplied** SQL structure because callers are untrusted. A service operation is different: the SQL/logic is **committed, reviewed code in the repository**, authored by the developer; only the *parameters* come from the authenticated, validated caller. So a handler may legitimately run a JOIN, a `GROUP BY` with `HAVING`, or a transaction — because the query *shape* is static and trusted, and every value is still bound. This is the clean line:

- Generic gateway = caller controls structure → must be allowlisted primitives only.
- Service operation = developer controls structure, caller controls parameters → arbitrary complexity is fine.

## Current state (context)

The pipeline routes only `POST /api/v1/{entity}/{select|insert|update|delete}` ([`src/Core/Router.php`](../../src/Core/Router.php)) to `ObjectController`. There is no second controller path. The middleware spine (HMAC, nonce, rate-limit, audit, and a *generic* permission check) is reusable; only the final controller + permission semantics differ for services.

## Scope

**In scope**
- A registry of `serviceName` → `operationName` → handler class.
- A `ServiceController` that resolves and runs a handler behind the existing middleware.
- Per-client permissions for service operations.
- A handler contract that receives validated input and a DB/repository accessor and returns structured data.
- Transactions *within* a single handler.

**Out of scope**
- Letting callers compose operations, chain services, or pass SQL.
- A visual workflow/orchestration engine.

## Security: handler resolution must be allowlisted

> **Non-negotiable.** `operation → handler class` resolution must use a **fixed, namespaced allowlist** — handlers live only under `App\Services\Operations\` and must implement the `ServiceOperation` interface. The registry stores a short *operation key*, never a fully-qualified class name from user/DB input. Instantiating an arbitrary class name from registry text would be the code-execution mirror of SQL injection.

Concretely: resolve `key → class` through a compile-time map (or `class_exists` check constrained to the allowed namespace **and** `is_subclass_of(..., ServiceOperation::class)`), and refuse anything else with `SERVICE_OPERATION_NOT_FOUND`.

## Design

**Routing** — **DECISION:** distinct prefix to avoid colliding with entity routes. Recommended:
```
POST /api/v1/services/{service}/{operation}
```
Extend `Router` to match this *before* the entity pattern; `{service}`/`{operation}` constrained to `[a-z][a-z0-9_]*`.

**Registry** — **DECISION:** start **config-based** (no migration), promotable to a table later.
```php
// config/services.php
return [
  'reports' => [
    'revenue_by_status' => ['handler' => 'reports.revenue_by_status', 'min_role' => 'select'],
    'ticket_summary'    => ['handler' => 'reports.ticket_summary'],
  ],
];
```
`handler` is an **operation key**, mapped to a class by a fixed allowlist map in code:
```php
// src/Services/Operations/OperationRegistry.php
private const MAP = [
  'reports.revenue_by_status' => RevenueByStatus::class,
  'reports.ticket_summary'    => TicketSummary::class,
];
```

**Handler contract**
```php
interface ServiceOperation {
    /** @param array<string,mixed> $input validated caller params @return array<string,mixed> */
    public function execute(array $input, ServiceContext $context): array;
    /** JSON-schema-ish param spec the framework validates before execute(). */
    public function inputSpec(): array;
}
```
- `ServiceContext` exposes a scoped DB handle / the existing `ObjectRepository` and the resolved client (so handlers can honour tenant scope). Handlers prefer the repository for allowlisted reads; raw PDO only for genuinely complex SQL, where the handler author owns correctness (parameters still bound).
- The framework validates `input` against `inputSpec()` **before** calling `execute()` — caller input never reaches a query unvalidated, even in services.

**Permissions** — **DECISION:** per-client service grants. Start config-based, keyed by client:
```php
// config/security.php → clients[clientId]['services'] = ['reports.revenue_by_status', …]
```
`ServiceController` checks the resolved client is granted the operation key, else `PERMISSION_DENIED`. (A DB table `api_client_service_permissions` can replace this when Phase 2 admin manages it.)

**Pipeline reuse** — services go through HTTPS → routing → body-limit → pre-auth RL → audit → HMAC → nonce → per-client RL, then a new `ServiceController` (instead of `ObjectController`). One security spine, two terminal controllers.

## Implementation plan

1. `src/Core/Router.php` — add the `/api/v1/services/{service}/{operation}` match (before the entity pattern); set `service`/`operation` attributes and a `route_kind` discriminator.
2. `src/Services/Operations/ServiceOperation.php` (interface) + `ServiceContext.php` (scoped DB/repo/client accessor).
3. `src/Services/Operations/OperationRegistry.php` — the fixed allowlist map + safe resolver.
4. `src/Controllers/ServiceController.php` — resolve op, check permission, validate input via `inputSpec()`, run, wrap in the standard `Response::success` envelope (`meta.operation` = `service/operation`).
5. `public/index.php` — branch on `route_kind`: entity → `ObjectController`, service → `ServiceController` (both behind the same pipeline).
6. `config/services.php` (new) + `config/security.php` `clients[].services` grants.
7. Reference handlers under `src/Services/Operations/reports/` (e.g. a revenue/aggregate report that does a real JOIN), used by the demo (Phase 4b).
8. New error codes: `SERVICE_NOT_FOUND` (404), `SERVICE_OPERATION_NOT_FOUND` (404), `SERVICE_INPUT_INVALID` (400). Permission reuses `PERMISSION_DENIED` (403).

## Security considerations

- Allowlisted handler resolution (above) — the single most important rule.
- Caller input validated against `inputSpec()` before execution; no parameter reaches SQL unbound.
- Tenant scope: handlers receive the resolved client and must apply its scope (helpers provided); document this as an author responsibility and cover it in handler tests.
- Transactions are allowed within a handler but must be wrapped (begin/commit/rollback) and never left open.
- Service ops are still rate-limited and audited like any request.

## Test plan / Definition of Done

- [ ] Router matches service routes without breaking entity routes (regression on the four entity actions).
- [ ] Unknown service/operation → 404; ungranted op → 403; bad input → 400 (before handler runs).
- [ ] Handler resolution rejects any key not in the allowlist map.
- [ ] A reference report handler (with a JOIN + aggregate) returns correct data on **both** drivers and respects tenant scope.
- [ ] A transactional handler commits/rolls back correctly (success + forced-failure paths).
- [ ] Service requests appear in the audit log; rate limiting applies.
- [ ] Docs: `docs/api-reference.md` gains a "Service operations" section; CHANGELOG `v0.5.0`.

## Unblocks

Phase 4b — the demo's reports/dashboards backed by real service operations.
