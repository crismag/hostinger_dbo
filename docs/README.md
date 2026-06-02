# php-dbo-gateway Documentation

Documentation for **php-dbo-gateway** — a secure, dependency-free PHP gateway for controlled MySQL and MariaDB object access. Start at the [project README](../README.md) for an overview, then use the guides below.

## Guides

| Document | Read it for |
| --- | --- |
| [Installation Guide](installation.md) | Requirements, shared-hosting & VPS installs, the bundled installer, manual setup, configuration, validation, and troubleshooting |
| [Security Guide](security-design.md) | HMAC signing, transport security, pre-auth abuse protection, replay protection, permissions, tenant scope, the mutation guard, the public demo, and auditing |
| [API Reference](api-reference.md) | Routes, headers, request/response shapes, and the full error-code table |
| [Service Authoring](service-authoring.md) | How to write named service operations (joins/reports/transactions) safely |
| [Architecture](architecture.md) | The request pipeline and the design constraints that keep the gateway small and dependency-free |
| [Database Schema](database-schema.md) | Security/registry tables and how to register your own entities |
| [Deployment](deployment.md) | HTTPS and proxy configuration, rate-limit storage, audit volume, and scheduled cleanup |
| [Migration](migration.md) | Moving from the former `hostinger_dbo` name |
| [Changelog](../CHANGELOG.md) | Versioned release history (Semantic Versioning) |

## Signed request examples

Working, copy-pasteable cURL examples: [select](../examples/curl/select.md) · [insert](../examples/curl/insert.md) · [update](../examples/curl/update.md) · [delete](../examples/curl/delete.md).

## Suggested reading order

1. **[Installation Guide](installation.md)** — get a working instance.
2. **[Security Guide](security-design.md)** — understand and tune the protections.
3. **[API Reference](api-reference.md)** — integrate a client.
4. **[Database Schema](database-schema.md)** — register your own objects.
5. **[Deployment](deployment.md)** — operate it in production.
