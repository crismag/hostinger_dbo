# API Reference

The complete request/response contract for **php-dbo-gateway**. For a conceptual overview see the [Security Guide](security-design.md); for setup see the [Installation Guide](installation.md).

## Base URL and routes

All operations are signed JSON `POST` requests under `/api/v1/`. There is **no** raw-SQL, batch, or schema-introspection endpoint — only these four routes exist:

```text
POST /api/v1/{entity}/select
POST /api/v1/{entity}/insert
POST /api/v1/{entity}/update
POST /api/v1/{entity}/delete
```

`{entity}` must be a registered entity name (lowercase, matching `[a-z][a-z0-9_]*`). Unregistered entities, non-`POST` methods, and unknown actions are rejected before authentication.

## Authentication

Every authenticated request must include these headers:

```text
Content-Type: application/json
X-Client-Id:  <your client id>
X-Timestamp:  <UTC ISO-8601, e.g. 2026-06-02T12:00:00Z>
X-Nonce:      <unique random value, [A-Za-z0-9._-]{1,128}>
X-Signature:  <lowercase hex HMAC-SHA256>
```

### HMAC signing

The signature is a lowercase-hex `HMAC-SHA256`, keyed by the client secret, over a canonical string of exactly five newline-separated fields:

```text
METHOD
PATH
TIMESTAMP
NONCE
SHA256_HEX_OF_RAW_BODY
```

- **METHOD** — uppercase HTTP method (`POST`).
- **PATH** — request path only, no query string (e.g. `/api/v1/projects/select`).
- **TIMESTAMP** — the exact `X-Timestamp` value.
- **NONCE** — the exact `X-Nonce` value.
- **SHA256_HEX_OF_RAW_BODY** — lowercase hex SHA-256 of the raw request body, byte-for-byte as sent.

The server recomputes the signature and compares with `hash_equals()` (timing-safe). The body you sign **must** be byte-identical to the body you send — re-serializing JSON differently will break the signature.

### Timestamp validation

`X-Timestamp` must use UTC `YYYY-MM-DDTHH:MM:SSZ` syntax and fall within the configured window (default 300 seconds) of server time. Outside the window the request is rejected. Keep client and server clocks in sync (NTP).

### Nonce / replay

Each `(client_id, nonce)` pair may be used once within the timestamp window. Generate a fresh random nonce per request (16+ random bytes hex is ideal). A reused nonce returns `AUTH_NONCE_REPLAYED`.

### Signing example (bash)

```bash
body='{"fields":["id","name"],"where":{"status":"active"},"limit":10}'
path='/api/v1/projects/select'
timestamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)
nonce=$(php -r 'echo bin2hex(random_bytes(16));')
body_hash=$(printf %s "$body" | sha256sum | cut -d' ' -f1)
canonical=$(printf 'POST\n%s\n%s\n%s\n%s' "$path" "$timestamp" "$nonce" "$body_hash")
signature=$(printf %s "$canonical" | openssl dgst -sha256 -hmac "$API_CLIENT_SECRET" -hex | awk '{print $NF}')

curl -X POST "https://your-domain$path" \
  -H 'Content-Type: application/json' \
  -H "X-Client-Id: $API_CLIENT_ID" -H "X-Timestamp: $timestamp" \
  -H "X-Nonce: $nonce" -H "X-Signature: $signature" -d "$body"
```

## CRUD operations

Request bodies must be JSON **objects** (not arrays). Only registry-allowlisted fields, filters, and order columns are accepted; everything else is rejected. Server-controlled tenant scope filters are merged into every query.

### select

```json
{
  "where":     {"tenant_id": "tenant_001", "status": "active"},
  "fields":    ["id", "name", "status", "created_at"],
  "limit":     50,
  "offset":    0,
  "order_by":  "created_at",
  "order_dir": "desc"
}
```

- `fields` — non-empty; each must be in the entity's `fields` allowlist.
- `where` — keys must be in `filterable`; values are scalars, bound as parameters.
- `limit` / `offset` — integers; `limit` is capped by the client's `max_rows_per_select`.
- `order_by` — must be in `orderable`; `order_dir` is `asc` or `desc`.

**Response** `200`:

```json
{"ok":true,"data":[{"id":1,"name":"Example","status":"active","created_at":"2026-06-01 09:00:00"}],"meta":{"request_id":"..."}}
```

### insert

```json
{"data": {"tenant_id": "tenant_001", "name": "Example", "status": "active"}}
```

- `data` keys must be in `insertable`. Server-enforced scope fields (e.g. `tenant_id`) are injected automatically and must not be sent by the client.

**Response** `201`:

```json
{"ok":true,"data":{"id":42},"meta":{"request_id":"..."}}
```

### update

```json
{"where": {"id": 123, "tenant_id": "tenant_001"}, "data": {"status": "archived"}}
```

- `where` keys must be in `filterable`; `data` keys in `updatable`.
- When `mutation_guard` is enabled, `where` must filter by the entity's primary key.

**Response** `200`:

```json
{"ok":true,"data":{"affected_rows":1},"meta":{"request_id":"..."}}
```

### delete

```json
{"where": {"id": 123, "tenant_id": "tenant_001"}}
```

- `where` keys must be in `filterable`; primary-key filter required under `mutation_guard`.

**Response** `200`:

```json
{"ok":true,"data":{"affected_rows":1},"meta":{"request_id":"..."}}
```

## Public demo (optional, unsigned)

When `public_demo` is enabled, the `select` route may be called **without** signature headers — but only for explicitly configured demo interfaces. Such requests are read-only, hard-capped (`max_limit`), restricted to allowlisted fields/filters, and have mandatory `required_where` filters injected (e.g. `is_demo = 1`) that the caller cannot remove. Everything else is denied with `PERMISSION_DENIED`. Demo callers are rate-limited per IP per minute/hour/day.

## Error handling

Errors share a stable envelope:

```json
{"ok":false,"error":{"code":"AUTH_INVALID_SIGNATURE","message":"Invalid request signature"},"meta":{"request_id":"..."}}
```

The `meta.request_id` correlates the response with the server audit log. Always branch on `error.code`, not on the human-readable `message`.

### Status codes

| Status | Meaning |
| --- | --- |
| `200` | Success (select/update/delete) |
| `201` | Created (insert) |
| `400` | Malformed JSON, invalid content type, or invalid request shape |
| `401` | Authentication failure (unified) or replayed nonce |
| `403` | HTTPS required, permission/field denial, or tenant-scope violation |
| `404` | Unknown route or entity |
| `405` | Method not allowed (only `POST`) |
| `409` | Object conflict (e.g. unique-key violation on insert/update) |
| `413` | Request body too large |
| `422` | Mutation guard: restrictive `where` required |
| `429` | Rate limit exceeded (pre-auth, per-client, or demo) |
| `500` | Internal error (details are logged, never returned) |

### Error codes

Routing & request shape:

| Code | Status | Meaning |
| --- | --- | --- |
| `ROUTE_NOT_FOUND` | 404 | No such route |
| `ROUTE_METHOD_NOT_ALLOWED` | 405 | Method other than `POST` |
| `ENTITY_NOT_FOUND` | 404 | Unknown or disabled entity |
| `ACTION_INVALID` | 400 | Action is not select/insert/update/delete |
| `REQUEST_INVALID_JSON` | 400 | Body is not a JSON object |
| `REQUEST_CONTENT_TYPE_INVALID` | 400 | `Content-Type` is not `application/json` |
| `REQUEST_BODY_TOO_LARGE` | 413 | Body exceeds `max_body_bytes` |

Authentication & rate limiting (all signed-auth failures surface as `AUTHENTICATION_FAILED`):

| Code | Status | Meaning |
| --- | --- | --- |
| `AUTHENTICATION_FAILED` | 401 | Unified signed-auth failure (prevents client enumeration) |
| `AUTH_NONCE_REPLAYED` | 401 | Nonce already used within the window |
| `HTTPS_REQUIRED` | 403 | Plaintext HTTP rejected while `require_https` is on |
| `RATE_LIMITED` | 429 | Pre-auth or public-demo IP rate limit exceeded |
| `RATE_LIMIT_EXCEEDED` | 429 | Per-client authenticated rate limit exceeded |

Authorization, validation & data:

| Code | Status | Meaning |
| --- | --- | --- |
| `PERMISSION_DENIED` | 403 | Client grant missing, or demo interface unavailable |
| `PERMISSION_LIMIT_EXCEEDED` | 403 | Requested `limit` exceeds the client's `max_rows_per_select` |
| `REQUEST_FIELD_NOT_ALLOWED` | 403 | A field/filter is not permitted for this client |
| `TENANT_SCOPE_VIOLATION` | 403 | Request conflicts with the client's enforced scope filter |
| `RESTRICTIVE_WHERE_REQUIRED` | 422 | `update`/`delete` did not filter by the primary key |
| `REQUEST_INVALID_FIELDS` | 400 | `fields` empty or not registry-allowlisted |
| `REQUEST_INVALID_ORDER` | 400 | `order_by`/`order_dir` invalid |
| `REQUEST_INVALID_PAGINATION` | 400 | `limit`/`offset` invalid |
| `REQUEST_INVALID_VALUE` | 400 | A bound value is nested or not a scalar |
| `OBJECT_CONFLICT` | 409 | Database constraint violation (e.g. duplicate unique key) |
| `INTERNAL_ERROR` | 500 | Unexpected server error (logged, not detailed to the caller) |

> Internally, signed-auth failures have specific reasons (`AUTH_CLIENT_INVALID`, `AUTH_IP_NOT_ALLOWED`, `AUTH_TIMESTAMP_EXPIRED`, `AUTH_INVALID_SIGNATURE`, and similar). These are written to the **server error log only**; the client always receives the single `AUTHENTICATION_FAILED` code so valid client IDs cannot be enumerated by comparing responses or timing.

## Security behavior

- **Permission failures** — a client without a grant for the entity/action gets `PERMISSION_DENIED` (403); a field/filter not permitted for the client gets `REQUEST_FIELD_NOT_ALLOWED` (403); a `limit` above the client's cap gets `PERMISSION_LIMIT_EXCEEDED` (403). Identifiers outside the registry are rejected at validation (`REQUEST_INVALID_FIELDS` / `REQUEST_INVALID_ORDER`, 400).
- **Tenant violations** — sending a field controlled by the client's `enforced_filters`, or otherwise conflicting with enforced scope, returns `TENANT_SCOPE_VIOLATION` (403) when `tenant_scope.on_violation = reject` (the default). A client can never widen its scope with an empty `where`.
- **Registry violations** — unknown entities, unregistered identifiers, nested values, and any attempt at raw SQL are rejected before reaching the database. Identifiers used in queries originate only from the registry; values are always bound through prepared statements.
- **Mutation safety** — under `mutation_guard`, `update`/`delete` without a primary-key filter return `RESTRICTIVE_WHERE_REQUIRED` (422), preventing accidental bulk changes.

## Curl examples

See [`examples/curl/select.md`](../examples/curl/select.md), [`insert.md`](../examples/curl/insert.md), [`update.md`](../examples/curl/update.md), and [`delete.md`](../examples/curl/delete.md).
