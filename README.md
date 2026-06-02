# php-dbo-gateway

**Secure, dependency-free PHP gateway for controlled MySQL and MariaDB object access.**

`php-dbo-gateway` is a thin, hardened REST layer that exposes a *fixed* set of database object operations — `select`, `insert`, `update`, `delete` — to trusted machine clients. Entity and field names are accepted only after database-backed registry validation, and every value is bound through PDO prepared statements. It runs on ordinary PHP shared hosting with no Composer packages, no Redis, no daemons, and no framework runtime.

It is **not** an ORM, a raw-SQL proxy, an admin UI, or a general web framework. It does one job: give applications a safe, authenticated, least-privilege HTTP interface to specific tables you explicitly register.

## Features

- **Dependency-free PHP** — no Composer, no Redis, no external services; just PHP + PDO
- **MySQL / MariaDB support** via PDO prepared statements
- **HMAC-SHA256 authentication** with request signing, timestamp skew limits, and nonce replay protection
- **Tenant isolation** — server-enforced per-client scope filters that callers cannot widen
- **Rate limiting** — a pre-authentication filesystem limiter plus per-client database limits
- **Audit logging** with body hashing (never body contents) and volume controls
- **Registry-driven access control** — only registered entities, fields, and filters are reachable
- **Mutation guard** — `update`/`delete` must target a primary key, preventing accidental bulk changes
- **Optional public demo** — disabled by default, read-only, hard-capped anonymous access
- **Shared-hosting and VPS compatible** — same code on cPanel hosting or a dedicated server

## Why php-dbo-gateway?

Applications frequently need a small, secure HTTP data layer — a mobile app reading projects, a serverless function writing records, a partner integration querying a scoped slice of your data. The usual options are heavy: stand up a full framework and ORM, hand-roll an API with ad-hoc auth, or (worst of all) expose raw SQL behind a thin proxy.

`php-dbo-gateway` fills that gap. You register which tables, fields, and filters are reachable; assign each client least-privilege permissions; and the gateway enforces authentication, authorization, tenant scope, rate limits, and auditing on every request. Because it deliberately avoids framework dependencies and version-specific language features, it deploys unchanged on inexpensive shared hosting as well as a tuned VPS.

The operating principle is: **trust nothing, authorize everything, constrain every query, rate limit every caller, scope every tenant, audit intelligently.**

## Quick Start

The fastest path is the bundled installer, which checks the environment, loads the schema, creates your first API client, writes configuration, and hardens file permissions.

**Interactive (SSH):**

```bash
bin/install.sh
```

**Non-interactive (full automation):**

```bash
INSTALL_NONINTERACTIVE=1 \
DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=app_gateway \
DB_USERNAME=app_user DB_PASSWORD=secret \
INSTALL_CREATE_DATABASE=1 INSTALL_WITH_EXAMPLES=1 \
INSTALL_CLIENT_ID=primary-client INSTALL_CLIENT_ACTIONS=select,insert \
bin/install.sh
```

**No shell access (FTP / cPanel)?** Upload the files, set the document root to `public/`, then open `https://your-domain/install.php` and follow the browser wizard. **Delete `public/install.php` when it finishes.**

Point the web server's document root at `public/` only, serve over HTTPS, and you are ready to make signed requests. See the **[Installation Guide](docs/installation.md)** for shared-hosting and VPS walkthroughs, manual installation, and troubleshooting.

## Security Model

Every authenticated request must send `Content-Type: application/json` plus `X-Client-Id`, `X-Timestamp`, `X-Nonce`, and `X-Signature`. The signature is a lowercase-hex HMAC-SHA256 over:

```text
METHOD
PATH
TIMESTAMP
NONCE
SHA256_HEX_OF_RAW_BODY
```

Before any object table is touched, middleware enforces — in order — HTTPS, body size and content type, a pre-authentication IP rate limit (filesystem-backed, no database read), HMAC signature validity, timestamp skew, client status and optional IP allowlists, nonce replay rejection, per-client rate limits, registry schema validation, client permissions, tenant scope, and the mutation guard. All authentication failures collapse to a single `AUTHENTICATION_FAILED` response so clients cannot be enumerated. Full request bodies and secrets are never logged.

Read the full **[Security Guide](docs/security-design.md)** for details.

## Documentation

- **[Installation Guide](docs/installation.md)** — requirements, shared-hosting & VPS install, configuration, validation, troubleshooting
- **[Security Guide](docs/security-design.md)** — authentication, authorization, tenant isolation, auditing
- **[API Reference](docs/api-design.md)** — routes, headers, request/response shapes, error codes
- **[Architecture](docs/architecture.md)** — request pipeline and design constraints
- **[Database Schema](docs/database-schema.md)** — security/registry tables and the entity registry
- **[Deployment](docs/deployment.md)** — HTTPS, proxies, rate-limit storage, scheduled cleanup

## Example Requests

Only four routes exist; there is no raw-SQL or schema-introspection endpoint:

```text
POST /api/v1/{entity}/select
POST /api/v1/{entity}/insert
POST /api/v1/{entity}/update
POST /api/v1/{entity}/delete
```

A signed `select` (the helper computes the canonical string and HMAC):

```bash
body='{"where":{"tenant_id":"tenant_001","status":"active"},"fields":["id","name","status"],"limit":50,"order_by":"created_at","order_dir":"desc"}'
path='/api/v1/projects/select'
timestamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)
nonce=$(php -r 'echo bin2hex(random_bytes(16));')
body_hash=$(printf %s "$body" | sha256sum | cut -d' ' -f1)
canonical=$(printf 'POST\n%s\n%s\n%s\n%s' "$path" "$timestamp" "$nonce" "$body_hash")
signature=$(printf %s "$canonical" | openssl dgst -sha256 -hmac "$API_CLIENT_SECRET" -hex | awk '{print $NF}')

curl -X POST "https://your-domain$path" \
  -H 'Content-Type: application/json' \
  -H "X-Client-Id: $API_CLIENT_ID" \
  -H "X-Timestamp: $timestamp" \
  -H "X-Nonce: $nonce" \
  -H "X-Signature: $signature" \
  -d "$body"
```

Successful response:

```json
{"ok":true,"data":[],"meta":{"request_id":"generated-id"}}
```

More signed examples: [select](examples/curl/select.md), [insert](examples/curl/insert.md), [update](examples/curl/update.md), [delete](examples/curl/delete.md).

## Deployment Models

| Model | Notes |
| --- | --- |
| **Shared hosting** (Hostinger, Bluehost, SiteGround, generic cPanel) | Upload, set the docroot to `public/`, run the web installer. No root or shell required. |
| **VPS / dedicated** (Apache, Nginx, PHP-FPM) | Full control over TLS, vhosts, and cron; use the CLI installer over SSH. |
| **Hybrid / behind a proxy** | Terminate TLS at a load balancer or CDN and list it in `trusted_proxies` so forwarded client IPs and scheme are honoured only from known peers. |

Each request runs synchronously in PHP with no long-running process, queue worker, or provider-specific integration. See the [Deployment](docs/deployment.md) guide.

## License

No license file is currently included in this repository. Until one is added, all rights are reserved by the author. If you intend to publish `php-dbo-gateway` as open source, add a `LICENSE` file (for example MIT or Apache-2.0) before release and update this section accordingly.
