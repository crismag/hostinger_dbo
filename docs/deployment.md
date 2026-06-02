# Deployment

The gateway can run on any PHP/MySQL shared host, managed web server, or VPS. It does not require a long-running process, framework runtime, Node.js service, or provider-specific integration.

## Requirements

- PHP 8.1 or newer with PDO MySQL enabled
- MySQL or MariaDB
- A web server configured with `public/` as the document root
- Apache rewrite support when using the included `public/.htaccess`

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

## Secret handling

Both local configuration files and `.env` are excluded from Git. Do not expose them through the document root, commit secrets, or enable a raw SQL route.
