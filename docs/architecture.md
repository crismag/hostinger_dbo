# Architecture

## Request flow

```text
HTTP request
  -> public/index.php
  -> Middleware pipeline
     -> HTTPS enforcement                 (HttpsMiddleware)
     -> fixed-route matching              (RoutingMiddleware)
     -> JSON body-size + content-type     (JsonBodyLimitMiddleware)
     -> pre-auth IP rate limiting         (PreAuthRateLimitMiddleware, filesystem, no DB)
     -> audit wrapper (mode-aware)        (AuditMiddleware)
     -> HMAC auth OR public-demo branch   (HmacAuthMiddleware)
     -> nonce replay protection           (ReplayProtectionMiddleware, skipped for demo)
     -> per-client/demo rate limiting     (RateLimitMiddleware)
     -> validation, permissions, tenant   (PermissionMiddleware)
        scope, and mutation guard
  -> Controllers/ObjectController.php
  -> Services/ObjectService.php
  -> Repositories/ObjectRepository.php
  -> Database/QueryBuilder.php
  -> PDO MySQL/MariaDB
```

The first four stages — HTTPS, routing, body limit, and pre-auth IP rate limiting — run *before* the audit wrapper and never touch the database, so plaintext requests, malformed routes, oversized bodies, and bot floods are rejected without database reads or audit writes.

The gateway is intentionally small and dependency-free. It is not a reusable web framework or ORM. `public/index.php` wires concrete classes explicitly. `SchemaRegistry` loads enabled entities from `api_entities`; the entity URL segment is never used directly as a table identifier. `QueryBuilder` receives only allowlisted identifiers and keeps request values in prepared-statement parameters. The pre-auth and public-demo rate limiters are backed by local files (`FilesystemRateLimiter`), so no Redis, daemon, or external service is required.

## Operating principle

Trust nothing. Authorize everything. Constrain every query. Rate limit every caller. Scope every tenant. Audit intelligently.

## Shared-hosting model

Each request runs synchronously in PHP. There is no daemon, queue worker, Node.js process, frontend, or Lambda client. Apache can forward clean URLs through `public/.htaccess`; PHP's built-in server can use `public/index.php` while developing locally.
