# API Design

## Routes

All operations use signed JSON `POST` requests:

| Route | Body |
| --- | --- |
| `/api/v1/{entity}/select` | `{"where":{"tenant_id":"tenant_001"},"fields":["id","name"],"limit":50,"offset":0,"order_by":"created_at","order_dir":"desc"}` |
| `/api/v1/{entity}/insert` | `{"data":{"tenant_id":"tenant_001","name":"Example","status":"active"}}` |
| `/api/v1/{entity}/update` | `{"where":{"id":123,"tenant_id":"tenant_001"},"data":{"status":"archived"}}` |
| `/api/v1/{entity}/delete` | `{"where":{"id":123,"tenant_id":"tenant_001"}}` |

Update and delete require a `where` that filters by the entity's primary key when `mutation_guard` is enabled (otherwise a non-empty `where`). Server-controlled tenant scope filters are merged into every query, so a client cannot read or mutate outside its configured scope. Nested values, unregistered entities, non-allowlisted identifiers, raw SQL, and schema introspection are rejected.

When `public_demo` is enabled, the same `select` route may be called **without** the signature headers; such requests are served only for explicitly configured demo interfaces, are read-only, and are hard-capped (row limit, allowed fields/filters, mandatory injected filters).

## Headers

```text
Content-Type: application/json
X-Client-Id: example-client
X-Timestamp: 2026-06-02T12:00:00Z
X-Nonce: unique-random-value
X-Signature: lowercase-hex-hmac-sha256
```

## Responses

Success:

```json
{"ok":true,"data":[],"meta":{"request_id":"generated-id"}}
```

Error:

```json
{"ok":false,"error":{"code":"AUTH_INVALID_SIGNATURE","message":"Invalid request signature"},"meta":{"request_id":"generated-id"}}
```

Expected statuses include `200`, `201`, `400`, `401`, `403`, `404`, `405`, `413`, `422`, `429`, and `500`. Stable error codes identify invalid JSON, oversized bodies, authentication failure, replayed nonces, permission failures, unknown entities, invalid fields, invalid filters, and rate-limit rejection.

Hardening-related codes:

| Code | Status | Meaning |
| --- | --- | --- |
| `HTTPS_REQUIRED` | 403 | Plaintext HTTP rejected while `require_https` is on |
| `RATE_LIMITED` | 429 | Pre-auth or public-demo IP rate limit exceeded |
| `AUTHENTICATION_FAILED` | 401 | Any signed-auth failure (unified to prevent client enumeration) |
| `TENANT_SCOPE_VIOLATION` | 403 | Request conflicts with the client's enforced scope filter |
| `RESTRICTIVE_WHERE_REQUIRED` | 422 | `update`/`delete` did not filter by the primary key |
| `PERMISSION_DENIED` | 403 | Demo interface not available, or grant missing |

## Curl examples

See [`examples/curl/select.md`](../examples/curl/select.md), [`insert.md`](../examples/curl/insert.md), [`update.md`](../examples/curl/update.md), and [`delete.md`](../examples/curl/delete.md).
