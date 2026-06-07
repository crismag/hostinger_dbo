# Administration

Day-to-day administration of a running gateway — managing entities, clients, and
permissions — is done with the **CLI admin tool** over SSH:

```bash
php bin/admin.php <command>
```

This is the safe, shell-only admin surface. A privileged *web* admin page is a
separate, opt-in component (see [Web admin](#web-admin-status)).

## Commands

| Command | Does |
| --- | --- |
| `status` | Backend driver + row counts (clients, entities, audit logs) |
| `health` | Environment + config checks (PHP, extensions, config present) |
| `entity:list` | List registered entities and their enabled flag |
| `entity:enable <name>` / `entity:disable <name>` | Toggle an entity on/off |
| `client:list` | List clients and their status |
| `client:create --id X [--name N] --entities a,b --actions select,insert` | Create a client + permissions; prints its HMAC secret **once** |
| `client:enable\|disable\|revoke <id>` | Change a client's status |
| `client:rotate <id>` | Issue a new HMAC secret (shown once) |
| `perms:list <client>` | Show a client's per-entity permissions |
| `perms:set --client X --entity Y --actions select,insert [--max 100]` | Set (upsert) a client's permissions for an entity |

## Secret handling

`client:create` and `client:rotate` generate a 256-bit HMAC secret, **show it
once**, and write it into `config/security.php` under `client_secrets` (the
file is re-secured to `0600`). The secret is never stored in the database and
never displayed again — capture it when shown. Rotating invalidates the old
secret immediately on the next request.

## Examples

```bash
# Stand up a read-only reporting client
php bin/admin.php client:create --id reporting-bot --entities tickets,agents --actions select

# Grant it update on tickets later
php bin/admin.php perms:set --client reporting-bot --entity tickets --actions select,update --max 200

# Rotate a leaked secret, then disable a retired client
php bin/admin.php client:rotate reporting-bot
php bin/admin.php client:disable old-service
```

## Relationship to the installer

The [installer](installation.md) bootstraps a deployment (schema, first client,
config). `bin/admin.php` is for **ongoing** management afterward, and reuses the
same identifier validation and config-writing routines. Entity *registration*
(new tables + policies) is still an install/manifest concern; the admin tool
manages enable/disable plus all client/permission changes.

## Web admin (status)

A browser-based admin page is **intentionally not enabled**. An always-on page
that can create clients and grant permissions is effectively root over the
gateway, and contradicts the installer's "self-lock and delete after use"
posture. Building it requires first ratifying an authentication model — the
recommended approach is **CLI-first with an optional localhost-only web view**
(never a world-reachable privileged endpoint). Until that is decided and built,
use the CLI tool above. See `docs/dev/phase-2-admin-setup.md` for the design.
