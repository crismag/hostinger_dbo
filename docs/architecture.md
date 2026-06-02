# Architecture

## Request flow

```text
HTTP request
  -> public/index.php
  -> Core/Router.php
  -> Middleware pipeline
     -> audit wrapper
     -> fixed-route matching
     -> JSON body-size check
     -> HMAC client authentication
     -> nonce replay protection
     -> DB-backed rate limiting
     -> registry schema and client-permission validation
  -> Controllers/ObjectController.php
  -> Services/ObjectService.php
  -> Repositories/ObjectRepository.php
  -> Database/QueryBuilder.php
  -> PDO MySQL/MariaDB
```

The gateway is intentionally small and dependency-free. It is not a reusable web framework or ORM. `public/index.php` wires concrete classes explicitly. `SchemaRegistry` loads enabled entities from `api_entities`; the entity URL segment is never used directly as a table identifier. `QueryBuilder` receives only allowlisted identifiers and keeps request values in prepared-statement parameters.

## Shared-hosting model

Each request runs synchronously in PHP. There is no daemon, queue worker, Node.js process, frontend, or Lambda client. Apache can forward clean URLs through `public/.htaccess`; PHP's built-in server can use `public/index.php` while developing locally.
