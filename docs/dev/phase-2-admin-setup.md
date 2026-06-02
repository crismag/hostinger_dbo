# Phase 2 — Admin / Setup Page

**Status:** Planned · **Depends on:** Phase 1 (consumes the app-definition model) · **Target version:** `v0.5.0`
**Delivery position:** 4th — *after* Demo 4a. An admin UI is only worth building once there is something to administer, and standing the demo up via CLI/installer/manifest first proves the non-UI setup path is sufficient.

## Goal

Provide ongoing administration of a running gateway: view the active backend, (re)initialize schema, register/disable entities, manage API clients and their permissions, configure public-demo access, and run installation/health checks — generalizing the one-shot installer into a managed surface.

## Current state (context)

The installer already implements much of this as a one-time flow ([`src/Install/Installer.php`](../../src/Install/Installer.php)): preflight/health checks, schema load, first-client creation, config generation, permission hardening, self-lock. Phase 2 promotes these into **repeatable management operations** and adds entity registration and permission editing, which currently require hand-written SQL.

## Security model (the gating decision)

> An always-on page that can create clients, grant permissions, and register entities is **root over the gateway**. It is the opposite of the installer's "self-lock and delete after use" posture. The authentication model must be decided **before** any code is written.

**DECISION (must ratify) — Admin authentication.** Recommended: **CLI-first, web view localhost-only.**

| Option | Summary | Risk |
| --- | --- | --- |
| **A. CLI-first + localhost-only web view (recommended)** | Admin actions live in `bin/` commands run over SSH; an optional read-mostly web view binds to `127.0.0.1`/`::1` only (the existing `HttpsMiddleware` localhost exemption logic already distinguishes these). Mutations require the CLI or an explicit localhost POST. | Lowest — no standing privileged internet endpoint |
| B. Authenticated web admin | Separate admin credential + hardened session (CSRF, login rate-limit, IP allowlist, forced HTTPS), explicitly firewalled. | Medium — real attack surface; must never be world-reachable |
| C. Full web admin app | Convenient, largest surface. | Highest — discouraged |

Cross-cutting requirements regardless of option:
- Admin auth is **separate from HMAC client auth** (admins are humans, clients are machines).
- All mutating actions require CSRF + are audited to `api_audit_logs` with an `admin` actor marker.
- The web view, if present, is **off by default**, gated by an explicit config flag and a bind-address check, and is never required for operation.
- No admin action can read or display HMAC secrets; secret creation shows a value once (like the installer).

## Scope

**In scope (operations)**
- **View backend** — driver, profile, DB reachability, schema presence, counts.
- **Initialize / migrate schema** — run driver-appropriate schema files idempotently (reuse `Installer::loadSchema`).
- **Register / update / disable entities** — write `api_entities` (`entity_name`, `table_name`, `primary_key_name`, `enabled`, `schema_json`) with full identifier + allowlist validation (reuse `EntitySchema` validation; reject invalid identifiers before write).
- **Manage API clients** — create/disable/revoke (`api_clients.status`), rotate secret (writes `config/security.php` `client_secrets`).
- **Manage permissions** — edit `api_client_permissions` (actions, `allowed_fields_json`, `allowed_filter_fields_json`, `max_rows_per_select`).
- **Configure public demo** — edit `public_demo` block in `config/security.php`.
- **Health checks** — preflight (PHP, extensions, perms), config validity, schema completeness, writable storage, clock skew note.

**Out of scope**
- Service-operation management (Phase 3 owns its own registry editing).
- Multi-admin RBAC, audit *viewer* UI (could be a later enhancement).

## Design

**Shared `AdminService` core** (mirrors the `Installer` pattern: pure logic, no transport). Both the CLI and the optional web view call it.

- `backendStatus(): array` — driver/profile/reachability/schema/counts.
- `registerEntity(name, table, pk, policy): void` — validate via `EntitySchema`, upsert `api_entities`.
- `setEntityEnabled(name, bool)`, `listEntities()`.
- `createClient(...)`, `setClientStatus(...)`, `rotateSecret(...)` — reuse `Installer::createClient`/secret generation; secret writes go to `config/security.php`.
- `setPermissions(clientId, entity, grants)`.
- `setPublicDemo(config)`.
- `health(): array`.

**CLI surface** — `bin/admin` (or subcommands on `bin/install.php`):
```
bin/admin status
bin/admin entity:register --name tickets --table tickets --pk id --policy policy.json
bin/admin entity:disable tickets
bin/admin client:create --id reports-svc --actions select
bin/admin client:rotate reports-svc
bin/admin perms:set --client reports-svc --entity tickets --select --fields id,subject,status
bin/admin demo:configure demo.json
bin/admin health
```

**Optional web view** — a single hardened PHP page reusing `AdminService`, bound localhost-only, off by default. Reuses the installer's CSRF + session hardening patterns ([`public/install.php`](../../public/install.php)).

## Implementation plan

1. `src/Admin/AdminService.php` — the shared core above; refactor reusable bits out of `Installer` (schema load, client create, secret gen, config writers) into shared helpers to avoid duplication.
2. Config-write helpers — extend the installer's `writeSecurityConfig` into incremental updates (read-modify-write the array) so permissions/demo edits don't clobber unrelated config.
3. `bin/admin` (CLI) — argument parsing + dispatch to `AdminService`; non-interactive friendly for automation.
4. (If Option A/B) `public/admin.php` — localhost-bind check first line; CSRF; reuse `AdminService`; off unless `admin.web_enabled=true`.
5. Audit integration — `AdminService` writes audit rows with an `admin` actor and the action performed.
6. Permission/entity validation — never write an `api_entities` row whose `schema_json` fails `EntitySchema` construction; never write a `client_id`/identifier failing the safe-identifier regex.

## Security considerations

- **Bind-address enforcement** for any web admin: reject unless `REMOTE_ADDR` ∈ {`127.0.0.1`,`::1`} (or a configured admin allowlist), evaluated *before* rendering anything.
- **CSRF + same-site session**, login throttling if Option B.
- **Audit every mutation**; never log secrets.
- **Fail closed**: web admin disabled by default; a missing/invalid `admin` config means "off," not "open."
- Entity registration is a privileged identifier-writing path — validate hard; a bad `table_name` here would later be quoted into SQL.

## Test plan / Definition of Done

- [ ] `AdminService` unit/smoke: register entity (valid + rejected-identifier), create/disable/rotate client, set permissions, configure demo, health.
- [ ] CLI commands perform each op and are idempotent where sensible.
- [ ] Web view (if built) refuses non-localhost `REMOTE_ADDR`, enforces CSRF, and is off by default.
- [ ] Admin mutations appear in `api_audit_logs`; no secret ever logged or re-displayed.
- [ ] Works on both drivers (Phase 1).
- [ ] Docs: an `admin.md` guide; CHANGELOG `v0.4.0`.
