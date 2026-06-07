# Security Policy

## Supported versions

php-dbo-gateway is pre-1.0; security fixes are applied to the latest released
minor version. Run a current `0.x` release.

| Version | Supported |
| --- | --- |
| latest `0.x` | ✅ |
| older | ❌ — please upgrade |

## Reporting a vulnerability

**Please do not open public issues for security problems.**

Report privately via GitHub's **"Report a vulnerability"** button on the
repository's **Security** tab (Security → Advisories → Report a vulnerability).
Include:

- a description and impact,
- steps to reproduce (a minimal request/payload is ideal),
- affected version/commit,
- any suggested remediation.

You can expect an acknowledgement within a few business days. Please allow a
reasonable period for a fix before any public disclosure (coordinated
disclosure).

## Scope

In scope: authentication (HMAC signing, replay), authorization (registry +
client permissions, tenant scope), the query/identifier allowlisting, the
mutation guard, the installer, and the admin tooling.

Out of scope: issues that require an already-compromised server or trusted
operator (e.g. a malicious `config/security.php`, a hostile service-operation
handler added to the codebase, or write access to the database).

## Hardening expectations

These are documented operator responsibilities, not vulnerabilities:

- Serve only the `public/` directory; keep `config/`, `storage/`, and database
  files outside the web root (`config/*.php` `0600`, sqlite files `0600`).
- Keep `require_https` on in production and front the gateway with TLS.
- Delete `public/install.php` after installation.
- Set `trusted_proxies` only to proxies you control.
- The pre-auth rate limiter is filesystem-backed and **fails open**; monitor the
  storage path, and use a shared backend for multi-instance deployments.
