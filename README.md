# PHP MySQL/MariaDB DBO REST Gateway

A thin, secure PHP 8.1+ database-object REST gateway for MySQL/MariaDB and PDO. It exposes controlled `select`, `insert`, `update`, and `delete` object operations to trusted machine clients.

## What this is not

This is not Laravel, Slim, Fat-Free, Doctrine, a general PHP framework, a multi-database ORM, a raw-SQL proxy, a UI, or an AWS Lambda client. Entity names and SQL identifiers are accepted only after database-backed registry validation. Request values are bound through PDO prepared statements.

## Install

1. Copy configuration templates and edit local values:

   ```bash
   cp config/database.example.php config/database.php
   cp config/security.example.php config/security.php
   ```

2. Create a MySQL/MariaDB database and import:

   ```bash
   mysql -u YOUR_USER -p YOUR_DATABASE < schema/security_tables.sql
   mysql -u YOUR_USER -p YOUR_DATABASE < schema/example_objects.sql
   ```

3. Insert an active client and its permissions. Store the matching HMAC secret in `config/security.php` or inject it through the environment:

   ```sql
   -- secret_hash is only used when allow_database_secrets=true, and must contain the plaintext HMAC secret value.
   INSERT INTO api_clients (client_id, client_name, secret_hash)
   VALUES ('example-client', 'Example service', 'configured-outside-database');

   INSERT INTO api_client_permissions (
       client_id, entity_name, can_select, can_insert, can_update, can_delete,
       max_rows_per_select, allowed_fields_json, allowed_filter_fields_json
   ) SELECT id, 'projects', TRUE, TRUE, TRUE, TRUE, 100,
       '["id","tenant_id","name","status","description","created_at","updated_at"]',
       '["id","tenant_id","status"]'
     FROM api_clients WHERE client_id = 'example-client';
   ```

4. Point the web document root to `public/`. For local development:

   ```bash
   php -S localhost:8000 -t public public/index.php
   ```

## Endpoints

Only these routes exist:

```text
POST /api/v1/{entity}/select
POST /api/v1/{entity}/insert
POST /api/v1/{entity}/update
POST /api/v1/{entity}/delete
```

There is no raw SQL or schema-introspection endpoint. See [API design](docs/api-design.md) and the signed [curl select example](examples/curl/select.md).

## Security summary

Every request requires JSON plus `X-Client-Id`, `X-Timestamp`, `X-Nonce`, and `X-Signature`. The signature is HMAC-SHA256 over method, path, timestamp, nonce, and the SHA-256 hash of the exact raw body. Middleware checks body size, timestamp skew, client status, optional IP restrictions, signature, nonce reuse, rate limits, registry schema, and client permissions before object-table access. Requests are audited using a body hash rather than body content.

Read [security design](docs/security-design.md), [database schema](docs/database-schema.md), [architecture](docs/architecture.md), and [deployment guidance](docs/deployment.md).
