# API Design

## Routes

All operations use signed JSON `POST` requests:

| Route | Body |
| --- | --- |
| `/api/v1/{entity}/select` | `{"where":{"tenant_id":"tenant_001"},"fields":["id","name"],"limit":50,"offset":0,"order_by":"created_at","order_dir":"desc"}` |
| `/api/v1/{entity}/insert` | `{"data":{"tenant_id":"tenant_001","name":"Example","status":"active"}}` |
| `/api/v1/{entity}/update` | `{"where":{"id":123,"tenant_id":"tenant_001"},"data":{"status":"archived"}}` |
| `/api/v1/{entity}/delete` | `{"where":{"id":123,"tenant_id":"tenant_001"}}` |

Update and delete require non-empty `where` objects. Nested values, unregistered entities, non-allowlisted identifiers, raw SQL, and schema introspection are rejected.

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

Expected statuses include `200`, `201`, `400`, `401`, `403`, `404`, `405`, `413`, `429`, and `500`. Stable error codes identify invalid JSON, oversized bodies, authentication failure, replayed nonces, permission failures, unknown entities, invalid fields, invalid filters, and rate-limit rejection.

## Curl examples

See [`examples/curl/select.md`](../examples/curl/select.md), [`insert.md`](../examples/curl/insert.md), [`update.md`](../examples/curl/update.md), and [`delete.md`](../examples/curl/delete.md).
