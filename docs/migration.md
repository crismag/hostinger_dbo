# Migration: `hostinger_dbo` → `php-dbo-gateway`

This project was renamed from `hostinger_dbo` to **`php-dbo-gateway`** as part of repositioning it as a reusable, public package. The rename is **cosmetic and non-breaking** — there are no changes to the HTTP API, request signing, database schema, or configuration format. Existing deployments continue to work unchanged.

## What changed

| Area | Change | Action required |
| --- | --- | --- |
| Repository name | `crismag/hostinger_dbo` → `crismag/php-dbo-gateway` | Update your git remote (below) |
| Project identity / docs | Rebranded to `php-dbo-gateway` | None |
| Composer | Added `composer.json` as `crismag/php-dbo-gateway` | Optional (see below) |
| HTTP API, routes, headers | **No change** | None |
| Request signing (HMAC) | **No change** | None |
| Database schema & tables | **No change** | None |
| `config/database.php`, `config/security.php` | **No change** | None |

No source file referenced the old name, so no code or configuration edits are needed.

## Update an existing clone

GitHub automatically redirects the old repository URL, so existing remotes keep working — but update them for clarity:

```bash
git remote set-url origin git@github.com:crismag/php-dbo-gateway.git
git remote -v        # verify
git fetch origin     # confirm connectivity
```

If you cloned over HTTPS instead of SSH:

```bash
git remote set-url origin https://github.com/crismag/php-dbo-gateway.git
```

## Rename the local directory (optional)

The working-directory name has no effect on the application, git, or any tracked file. Rename it whenever convenient:

```bash
mv /path/to/hostinger_dbo /path/to/php-dbo-gateway
```

If you use an IDE, close the workspace first, rename the folder, then reopen it so editor paths refresh.

## Existing production deployments

Nothing to do. The deployed code, database, and config are unaffected by the rename. If you redeploy from the renamed repository:

1. Pull or re-clone from the new remote.
2. Keep your existing `config/database.php`, `config/security.php`, and `storage/` — they are unchanged and remain gitignored.
3. Optionally run `bin/harden-permissions.sh` to re-assert secure permissions.

## Adopting Composer (optional)

The package now ships a `composer.json` (`type: project`, PSR-4 `App\` → `src/`). You can:

- Install dev tooling and a generated autoloader: `composer install`
- Start a fresh deployment from the package (once published to Packagist): `composer create-project crismag/php-dbo-gateway`

Composer is **not required** — the front controller falls back to a built-in autoloader when `vendor/autoload.php` is absent, so the gateway still runs on hosts without Composer.

## Versioning

`php-dbo-gateway` follows [Semantic Versioning](https://semver.org/). The first tagged release under the new name is **v0.1.0**; see the [CHANGELOG](../CHANGELOG.md). Pre-1.0 minor versions may include breaking changes, which will always be documented in the changelog.
