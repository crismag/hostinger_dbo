# Deployment

The gateway can run on any PHP/MySQL shared host, managed web server, or VPS. It does not require a long-running process, framework runtime, Node.js service, or provider-specific integration.

## Requirements

- PHP 8.1 or newer with PDO MySQL enabled
- MySQL or MariaDB
- A web server configured with `public/` as the document root
- Apache rewrite support when using the included `public/.htaccess`
- A TLS certificate (HTTPS is required by default; see below)
- A writable temporary directory for the filesystem rate limiter (defaults to the system temp dir)

## Installation

1. Upload or clone the repository on the target server.
2. Configure the domain or virtual host document root to the repository's `public/` directory so source and configuration files are not web-accessible.
3. Create a MySQL/MariaDB database and a database user with access to it.
4. Copy `config/database.example.php` to `config/database.php` and set the database credentials.
5. Copy `config/security.example.php` to `config/security.php`, replace the example HMAC secret, and keep `allow_database_secrets` disabled when using configured secrets.
6. Import `schema/security_tables.sql`, followed by `schema/example_objects.sql`, using your database administration tool or the MySQL CLI.
7. Insert client and permission records as shown in the README.
8. For Apache, confirm `public/.htaccess` is present so clean API URLs route to `public/index.php`. For another web server, configure an equivalent front-controller rewrite.

## Local development server

PHP's built-in server can route requests through the front controller during local development:

```bash
php -S localhost:8000 -t public public/index.php
```

## HTTPS

`require_https` defaults to `true`. Plaintext HTTP is rejected with `HTTPS_REQUIRED`, except for `127.0.0.1`/`::1` and when `dev_mode` is `true`. When the gateway sits behind a TLS-terminating proxy or load balancer, list that proxy in `trusted_proxies` so `X-Forwarded-Proto` and `X-Forwarded-For` are honoured only from known peers. Keep `require_https` on in production and leave `trusted_proxies` empty when the gateway is directly exposed.

## Rate-limit storage

The pre-authentication and public-demo limiters store per-IP counters as local files. By default they live under the system temp directory; set `pre_auth_rate_limit.storage_dir` to a private, writable path if the temp directory is volatile or shared. When running behind a proxy, set `trusted_proxies` so these counters key on the verified client IP instead of the proxy address. The directory is created automatically and never needs to be web-accessible.

## Scheduled cleanup

Run the retention utility from cron (for example daily) to purge expired nonces, stale rate-limit buckets, audit logs older than `audit.retention_days`, and orphaned filesystem counters:

```bash
php bin/cleanup.php
```

## Audit volume

For public-facing or demo deployments, keep `audit.mode` at `authenticated_only` (the default) or `sampled` so unauthenticated traffic does not fill `api_audit_logs`. Use `all` only when you need a complete request trail.

## Secret handling

Both local configuration files and `.env` are excluded from Git. Do not expose them through the document root, commit secrets, or enable a raw SQL route.
