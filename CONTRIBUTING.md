# Contributing

Thanks for your interest in php-dbo-gateway. This project is deliberately small,
secure, and dependency-free — contributions should preserve those properties.

## Development setup

No Composer packages are required to run the gateway.

```bash
git clone https://github.com/crismag/php-dbo-gateway.git
cd php-dbo-gateway

# Quickest path: stand up the SQLite demo (creates a working install)
php apps/demo-ticketdesk/setup.php

# Run the test suite (SQLite-backed; no external services needed)
php tests/gateway_smoke.php
php tests/hardening_smoke.php
php tests/driver_matrix_smoke.php
php tests/service_registry_smoke.php
php tests/admin_smoke.php
# query_features_smoke.php needs a MySQL config (skips cleanly without one)
```

CI runs lint + this suite across PHP 8.1–8.4 (plus a real MySQL job), so make
sure everything is green locally first.

## Coding standards

- **PHP 8.1+**, `declare(strict_types=1)`, full type hints, `final` classes.
- 4-space indent, PSR-12-ish style. Keep classes small and single-purpose.
- No new runtime dependencies. No frameworks.

## The security invariants (non-negotiable)

Any change touching data access must preserve these:

1. **Identifiers are registry-allowlisted** — entity/table/field/operator names
   come only from the registry, never from caller input.
2. **Values are parameter-bound** — never concatenate request values into SQL.
3. **No raw SQL from callers**, and **no class names resolved from config/DB**
   (service handlers resolve through the compile-time allowlist only).
4. **Least privilege** — per-client permissions and tenant scope still apply.

Complex/multi-table/transactional logic belongs in a **service operation**
(`src/Services/Operations/…`, allowlisted in `OperationRegistry`), not in the
generic gateway. See [docs/service-authoring.md](docs/service-authoring.md).

## Pull requests

- One focused change per PR; include tests for new behaviour.
- Update `CHANGELOG.md` (under `[Unreleased]`) and any affected docs.
- Ensure `php -l` is clean and the smoke suite passes.
- Describe the change, the security impact (if any), and how you verified it.

## Reporting security issues

Do **not** open a public issue — see [SECURITY.md](SECURITY.md).
