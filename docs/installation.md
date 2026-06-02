# Installation Guide

This guide covers installing **php-dbo-gateway** on shared hosting and on a VPS or dedicated server, using either the bundled installer or a manual setup.

## Overview

`php-dbo-gateway` is a secure, dependency-free PHP gateway that exposes controlled `select`, `insert`, `update`, and `delete` operations over MySQL or MariaDB to trusted machine clients. It requires no Composer packages, no Redis, and no long-running processes — a standard PHP + MySQL host is enough. Installation consists of four things: place the code, create and load a database, write configuration (including your first API client), and serve the `public/` directory over HTTPS. The bundled installer automates all four.

## Requirements

| Component | Requirement |
| --- | --- |
| **PHP** | PHP 8.1+ (tested through PHP 8.4.21) |
| **PDO** | `pdo_mysql` extension enabled |
| **Database** | MySQL 5.7+/8.0+ or MariaDB 10.3+ |
| **JSON** | `ext-json` (bundled with PHP by default) |
| **mbstring** | `ext-mbstring` enabled |
| **cURL** | Not required by the gateway. The signed-request *examples* and many clients use cURL; the server itself does not depend on it. |
| **Web server** | Apache (with `mod_rewrite` for the bundled `.htaccess`), Nginx, or PHP-FPM, with the document root set to `public/` |
| **TLS** | An HTTPS certificate. HTTPS is enforced by default. |
| **File permissions** | The PHP process must be able to read `config/` and write `storage/`. Secrets are stored owner-only (see [File Permissions](#file-permissions)). |

> The project intentionally avoids framework dependencies and modern language features that would unnecessarily restrict PHP compatibility. This is why it runs on inexpensive shared hosting and on tuned VPS environments without code changes.

Verify your PHP environment:

```bash
php -v                       # 8.1 or newer
php -m | grep -E 'pdo_mysql|mbstring|json'
```

## Installation Methods

There are two installer front-ends backed by the same logic, plus a fully manual path:

- **CLI installer** (`bin/install.sh`) — best when you have SSH access. Interactive or fully automated.
- **Web installer** (`public/install.php`) — best for FTP/cPanel uploads with no shell. A browser wizard that self-disables when finished.
- **Manual** — copy config templates and import SQL yourself.

### Shared Hosting

Applies to Hostinger, Bluehost, SiteGround, and generic cPanel hosting.

1. **Create a database and user.** In hPanel / cPanel → *MySQL Databases*, create a database and a user, and grant the user all privileges on that database. Note the host (often `localhost`), database name, username, and password.
2. **Upload the code.** Upload the repository via the File Manager, FTP, or `git clone` into a directory *above* your web root (for example `~/apps/php-dbo-gateway`).
3. **Point the document root at `public/`.** In hPanel/cPanel, set the domain or subdomain document root to the project's `public/` directory so `config/`, `src/`, and `schema/` are never web-accessible. (If your host forces the docroot to `public_html`, place only the contents of `public/` there and move the rest of the project above it, adjusting the `require` path in `public/index.php` accordingly.)
4. **Run the web installer.** Visit `https://your-domain/install.php`. The wizard will:
   - check the PHP environment,
   - test the database connection (optionally creating the database),
   - load the schema,
   - create your first API client and show its HMAC secret **once**,
   - write `config/database.php` and `config/security.php`,
   - apply secure file permissions, and
   - lock itself.
5. **Save the HMAC secret** shown on the final screen — it is not displayed again.
6. **Delete `public/install.php`.** This is important; the installer reminds you on completion.

> No SSH? That's fine — the web installer needs only a browser. If you *do* have SSH, the CLI installer below is faster and scriptable.

### VPS / Dedicated Server

Applies to Apache, Nginx, and PHP-FPM setups where you have root or shell access.

1. **Clone the code** outside the web root:

   ```bash
   git clone <your-fork-url> /var/www/php-dbo-gateway
   cd /var/www/php-dbo-gateway
   ```

2. **Run the CLI installer.** Interactive:

   ```bash
   bin/install.sh
   ```

   Or fully automated for provisioning scripts:

   ```bash
   INSTALL_NONINTERACTIVE=1 \
   DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=app_gateway \
   DB_USERNAME=app_user DB_PASSWORD='strong-secret' \
   INSTALL_CREATE_DATABASE=1 INSTALL_WITH_EXAMPLES=1 \
   INSTALL_CLIENT_ID=primary-client INSTALL_CLIENT_NAME='Primary service' \
   INSTALL_CLIENT_ACTIONS=select,insert,update,delete \
   INSTALL_REQUIRE_HTTPS=1 \
   INSTALL_TRUSTED_PROXIES=10.0.0.1 \
   bin/install.sh
   ```

   The installer prints the generated HMAC secret. Store it in your secret manager.

3. **Configure the web server** with `public/` as the document root.

   **Apache** (the bundled `public/.htaccess` already routes clean URLs):

   ```apache
   <VirtualHost *:443>
       ServerName api.example.com
       DocumentRoot /var/www/php-dbo-gateway/public
       <Directory /var/www/php-dbo-gateway/public>
           AllowOverride All
           Require all granted
       </Directory>
       # ... TLS configuration ...
   </VirtualHost>
   ```

   **Nginx + PHP-FPM** (front-controller rewrite):

   ```nginx
   server {
       listen 443 ssl;
       server_name api.example.com;
       root /var/www/php-dbo-gateway/public;
       index index.php;

       location / {
           try_files $uri $uri/ /index.php$is_args$args;
       }
       location ~ \.php$ {
           include fastcgi_params;
           fastcgi_pass unix:/run/php/php8.3-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
       }
       # Never serve dotfiles or config.
       location ~ /\.(?!well-known) { deny all; }
   }
   ```

4. **Schedule cleanup** (retention + nonce/rate-limit purging):

   ```cron
   17 3 * * * /usr/bin/php /var/www/php-dbo-gateway/bin/cleanup.php
   ```

### Manual Installation

If you prefer not to use the installer:

```bash
cp config/database.example.php config/database.php   # set DB credentials
cp config/security.example.php config/security.php   # set HMAC secret(s) and options

mysql -u USER -p DATABASE < schema/security_tables.sql
mysql -u USER -p DATABASE < schema/example_objects.sql   # optional example objects
```

Then insert a client and permissions:

```sql
-- secret_hash is only used when allow_database_secrets=true; otherwise it is a non-sensitive marker.
INSERT INTO api_clients (client_id, client_name, secret_hash)
VALUES ('primary-client', 'Primary service', 'managed-in-config');

INSERT INTO api_client_permissions (
    client_id, entity_name, can_select, can_insert, can_update, can_delete, max_rows_per_select
) SELECT id, 'projects', TRUE, TRUE, FALSE, FALSE, 100
  FROM api_clients WHERE client_id = 'primary-client';
```

Put the matching HMAC secret in `config/security.php` under `client_secrets['primary-client']`, then harden permissions:

```bash
bin/harden-permissions.sh
```

## Directory Layout

After installation a deployed instance looks like this (only `public/` is web-accessible):

```text
php-dbo-gateway/
├── public/            # WEB ROOT — index.php front controller, .htaccess
│   ├── index.php
│   └── install.php    # delete after installation
├── config/            # NOT web-accessible — database.php, security.php (0600)
├── src/               # Application code (middleware, services, security, install)
├── schema/            # SQL: security_tables.sql, example_objects.sql
├── storage/           # Runtime: rate-limit counters, install lock (0700)
├── bin/               # install.sh, install.php, harden-permissions.sh, cleanup.php
├── examples/curl/     # Signed request examples
└── docs/              # This documentation
```

`config/`, `src/`, `schema/`, and `storage/` must remain **outside** the served directory. With the docroot set to `public/`, they already are.

## Configuration

The installer generates two files; you can also edit them directly. Both must stay out of the web root and at mode `0600`.

### Database configuration — `config/database.php`

Self-contained connection settings (host, port, database, username, password, charset). The installer writes inline values; to source from the environment instead, replace a value with `getenv('DB_...')`.

### Gateway & security configuration — `config/security.php`

Generated from `config/security.example.php`. Key sections:

| Setting | Purpose |
| --- | --- |
| `timestamp_window_seconds` | Allowed clock skew for signed requests (default 300) |
| `max_body_bytes` | Request body cap (default 65536) |
| `max_requests_per_minute` | Per-client authenticated rate limit |
| `client_secrets` | `client_id => HMAC secret`. The credential store — treat this file accordingly. |
| `allow_database_secrets` | Keep `false` when secrets live in this file |
| `require_https` | Enforce HTTPS (default `true`) |
| `trusted_proxies` | IPs/CIDRs of TLS-terminating proxies whose `X-Forwarded-*` headers are honoured |
| `pre_auth_rate_limit` | Pre-authentication IP abuse gate (filesystem-backed) |
| `audit` | Audit `mode`, `sample_rate`, and `retention_days` |
| `mutation_guard` | Require a primary-key filter on `update`/`delete` |
| `tenant_scope` | `on_violation`: `reject` (recommended) or `override` |
| `public_demo` | Optional anonymous read-only access (disabled by default) |

### Tenant configuration

Per-client server-enforced scope lives under `clients` in `config/security.php`:

```php
'clients' => [
    'primary-client' => [
        'enforced_filters' => ['tenant_id' => 1001], // merged into every where / insert data
        'allow_bulk_updates' => false,               // exempt from the mutation guard if true
    ],
],
```

Clients must **not** send the enforced fields themselves. With `tenant_scope.on_violation = reject`, a conflicting value is rejected; a caller can never widen its scope with an empty `where`.

### API credentials

Each client has a `client_id` (stored in `api_clients`) and an HMAC secret (stored in `config/security.php`). Generate strong secrets — the installer uses 256 bits of `random_bytes`. To rotate a secret, update `client_secrets` and notify the client; to add clients, insert a row in `api_clients`, grant permissions in `api_client_permissions`, and add the secret to `client_secrets`.

### Environment variables (12-factor friendly)

The config files are PHP arrays, which fail closed if ever served and work on every host. For containerized or 12-factor deployments you can keep secrets out of files entirely: the example configs read from the environment (and a project-root `.env`), with the PHP file as a thin loader. Recognised variables:

| Variable | Aliases | Used by |
| --- | --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_PASSWORD`, `DB_CHARSET` | — | `config/database.php` |
| `DB_NAME` | `DB_DATABASE` | `config/database.php` |
| `DB_USER` | `DB_USERNAME` | `config/database.php` |
| `GATEWAY_CLIENT_ID` | — | `config/security.php` (primary client id) |
| `GATEWAY_SECRET` | `API_CLIENT_EXAMPLE_SECRET` | `config/security.php` (primary client HMAC secret) |

Environment values take precedence over `.env`. This is a **hybrid** model: file-based by default (best for shared hosting), environment-first when you set the variables (best for VPS, containers, and CI). Choose one consistently per environment.

### Storage driver (MySQL or SQLite)

`config/database.php` selects the backend with a `driver` key (`mysql` default, or `sqlite`):

```php
return [
    'driver' => 'sqlite',                                  // or 'mysql'
    'mysql'  => ['host' => '127.0.0.1', 'port' => '3306', 'database' => '...', 'username' => '...', 'password' => '...', 'charset' => 'utf8mb4'],
    'sqlite' => ['path' => __DIR__ . '/../storage/gateway.sqlite'],  // keep outside the web root, 0600
];
```

The installer asks for the driver (or set `DB_DRIVER=sqlite DB_SQLITE_PATH=...`). For SQLite it loads `schema/sqlite/*.sql` and creates the database file automatically — no server, no `CREATE DATABASE`. The query layer (CRUD, LIKE, GROUP BY, aggregates) and all security behave identically on both drivers.

### Environment profiles (`APP_ENV`)

`config/profiles.php` defines `dev` / `demo` / `prod` overrides that are deep-merged over `config/security.php` when `APP_ENV` is set. Profiles are **opt-in** — with no `APP_ENV`, the written config is used unchanged.

| Profile | require_https | dev_mode | audit | public_demo |
| --- | --- | --- | --- | --- |
| `prod` | on | off | authenticated_only | off |
| `demo` | on | off | sampled | **on** |
| `dev` | off | on | all | inherited |

```bash
APP_ENV=prod php -S 0.0.0.0:8000 -t public public/index.php   # or set APP_ENV in your vhost/FPM env
```

### Install an application from a manifest

An application can be defined declaratively in an `app.json` and installed in one command:

```json
{ "app": "ticketdesk", "driver": "sqlite", "database": "storage/ticketdesk.sqlite",
  "entities": ["tickets", "customers"], "services": ["reports"] }
```

```bash
bin/install.php --app path/to/app.json
```

This loads the gateway schema, imports the app's object tables (`data/schema.sql`), registers the listed entities from `data/registry.json` (one policy object per entity), and creates a scoped client. The manifest names *what* the app uses; the per-entity field/filter/searchable/groupable/aggregatable policies live in `registry.json`.

## Validation Steps

### 1. Health check

Confirm the gateway boots and routes. An unsigned request to a real route should return a clean `401` (proving the pipeline and auth are active):

```bash
curl -s -X POST https://your-domain/api/v1/projects/select \
  -H 'Content-Type: application/json' -d '{"fields":["id"],"limit":1}'
```

Expected:

```json
{"ok":false,"error":{"code":"AUTHENTICATION_FAILED","message":"Authentication failed"},"meta":{"request_id":"..."}}
```

### 2. Sample signed API request

```bash
export API_CLIENT_ID=primary-client
export API_CLIENT_SECRET=...        # the secret from installation

body='{"fields":["id","name","status"],"where":{"status":"active"},"limit":3}'
path='/api/v1/projects/select'
timestamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)
nonce=$(php -r 'echo bin2hex(random_bytes(16));')
body_hash=$(printf %s "$body" | sha256sum | cut -d' ' -f1)
canonical=$(printf 'POST\n%s\n%s\n%s\n%s' "$path" "$timestamp" "$nonce" "$body_hash")
signature=$(printf %s "$canonical" | openssl dgst -sha256 -hmac "$API_CLIENT_SECRET" -hex | awk '{print $NF}')

curl -s -X POST "https://your-domain$path" \
  -H 'Content-Type: application/json' \
  -H "X-Client-Id: $API_CLIENT_ID" -H "X-Timestamp: $timestamp" \
  -H "X-Nonce: $nonce" -H "X-Signature: $signature" -d "$body"
```

### 3. Expected response

```json
{"ok":true,"data":[],"meta":{"request_id":"generated-id"}}
```

An empty `data` array is correct on a fresh install (no rows yet). A non-`200` response with a stable error code points you at the issue — see below and the [API Reference](api-reference.md).

## Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `Security configuration not found` (500) | `config/security.php` missing | Run the installer, or copy the example and edit it |
| `Unable to connect to the database` (500) | Wrong credentials, host, or port in `config/database.php` | Re-check values; on shared hosting the host is often `localhost` |
| `HTTPS_REQUIRED` (403) | Plaintext HTTP while `require_https` is on | Use `https://`; behind a proxy, add it to `trusted_proxies`; for local dev set `dev_mode` |
| `AUTHENTICATION_FAILED` (401) on a signed request | Clock skew, wrong secret, or canonical-string mismatch | Ensure the server clock is correct, the secret matches, and the body is byte-identical to what was signed |
| `RATE_LIMITED` (429) immediately | Pre-auth limiter counting a proxy IP | Set `trusted_proxies` so counters key on the real client IP |
| `AUTH_NONCE_REPLAYED` (401) | Reused nonce | Generate a fresh random nonce per request |
| `TENANT_SCOPE_VIOLATION` (403) | Client sent a field controlled by `enforced_filters` | Remove that field from the request; the server injects it |
| `RESTRICTIVE_WHERE_REQUIRED` (422) | `update`/`delete` without a primary-key filter | Filter by the primary key, or grant `allow_bulk_updates` for trusted clients |
| Config files readable by others | Permissions not hardened | Run `bin/harden-permissions.sh` |
| `chmod failed` in the hardener | PHP/user is not the file owner, or web server runs as a different user | Run as the owner; for a separate web user use `CONFIG_DIR_MODE=750 CONFIG_FILE_MODE=640 bin/harden-permissions.sh` and `chgrp` the configs |

## File Permissions

By default secrets are owner-only, which suits the common shared-hosting model where PHP executes as the file owner:

- `config/` directory: `0700`, `config/*.php`: `0600`
- `storage/` and `storage/ratelimit/`: `0700`
- `bin/*` scripts: `0750`
- `public/`: `0755`, `public/index.php` and `.htaccess`: `0644`

If your web server runs as a **separate** user from the file owner, relax the config modes and set group ownership:

```bash
CONFIG_DIR_MODE=750 CONFIG_FILE_MODE=640 bin/harden-permissions.sh
chgrp -R www-data config storage
```

## Next Steps

- Review the [Security Guide](security-design.md) and confirm `require_https`, `mutation_guard`, and `tenant_scope` suit your deployment.
- Register your own objects in `api_entities` (see the [Database Schema](database-schema.md)).
- Read the [API Reference](api-reference.md) for request/response shapes and error codes.
- Set up the [scheduled cleanup](deployment.md#scheduled-cleanup) cron job.
