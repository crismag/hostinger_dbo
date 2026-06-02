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

## Replay protection and rate limiting

After signature validation, `NonceStore` inserts the nonce into `api_nonces`. The `(client_id, nonce)` unique key atomically rejects reuse. `RateLimitService` increments a per-client, per-IP, per-minute bucket in `api_rate_limits` and returns HTTP `429` after the configured threshold.

## Registry and permissions

Enabled entities come from `api_entities`. Registry JSON allowlists selectable, insertable, updatable, filterable, and orderable identifiers. `api_client_permissions` separately restricts each client's actions, visible fields, filter fields, and maximum select size. Update and delete require a non-empty restrictive `where` object.

## Audit logging

`AuditMiddleware` wraps routed requests and writes success or failure metadata to `api_audit_logs`: request ID, resolved client when available, entity, action, method, path, body hash, IP, response status, error code, and duration. Full bodies and secrets are not logged. Audit write failures are logged server-side without replacing the API result.

## Why raw SQL is forbidden

Raw SQL would bypass entity allowlists, field permissions, restrictive mutation checks, and identifier validation. The gateway exposes only the four object operations. Values use PDO prepared statements; SQL identifiers must originate from registry allowlists.
