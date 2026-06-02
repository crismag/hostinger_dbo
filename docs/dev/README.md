# Development Roadmap & Implementation Plans

> **Internal engineering docs.** This directory holds development context and implementation plans for upcoming work. It is intentionally separate from the public documentation set in [`docs/`](../README.md) and is not linked from the user-facing docs index. Audience: contributors implementing the roadmap.

## Purpose

Each phase document expands a roadmap item into: current-state context, goals, in/out scope, design decisions (with recommendations and open questions), a concrete implementation plan (files and steps), security considerations, a test plan, and a definition of done.

## Roadmap (delivery order)

The order is risk-first: tackle the biggest architectural unknown early, ship a demonstrable artifact before management tooling, and let the demo prove the CLI/manifest setup path is sufficient before investing in an admin UI.

| # | Phase | Version | Depends on | Why here |
| --- | --- | --- | --- | --- |
| 1 | [Application Configuration Foundation](phase-1-configuration-management.md) (incl. App Definition Framework) | `v0.3.0` | — | The unlock. Multi-driver config, profiles, **app-definition model**, driver-aware installer, SQLite. |
| 2 | [Service Registry MVP](phase-3-service-registry.md) | `v0.35` | 1 | **The architectural bet.** Whether `/service/operation` is a clean extension model decides the platform's future. De-risk it first. |
| 3 | [Demo TicketDesk — 4a](phase-4-demo-app.md) | `v0.4.0` | 1 | First clickable artifact: CRUD + query controls on SQLite. Portfolio/demo value, validates Phase 1. |
| 4 | [Admin & Setup](phase-2-admin-setup.md) | `v0.5.0` | 1 (consumes app-def) | Manage a running system — valuable only once there's something to administer. |
| 5 | [Demo TicketDesk — 4b](phase-4-demo-app.md#split-for-early-delivery) | `v0.6.0` | 2 | Reports/complex flows via service operations. |

*Version labels follow the maintainer's scheme; `v0.35` (Service Registry MVP) can be normalized to `v0.4.0` later under strict semver. The already-committed query controls remain pending a `v0.2.0` tag.*

## Why this order (the rationale)

- **The biggest unknown is not configuration — it's the extension model.** Config is known engineering; the Service Registry is the bet that everything beyond CRUD/LIKE/GROUP BY rests on. If `/serviceName/operation` doesn't compose elegantly, the whole platform thesis is at risk, so it comes right after the foundation it needs.
- **Demo before Admin.** A working demo yields screenshots, videos, real workflows, and portfolio value far sooner than an admin panel — and nobody uses an admin panel until there is something worth administering.
- **Admin after Demo is a forcing function.** Standing up the demo via CLI/installer/manifest *first* proves the non-UI setup path is sufficient before a GUI is built.

## The shift this roadmap encodes

With the **App Definition Framework** (Phase 1.5, folded into v0.3.0), a single declarative manifest seeds configuration, the installer, admin, and demo apps. At that point the project stops looking like "a PHP database gateway" and starts looking like a **deployable application data-service platform** — apps are *defined*, not hand-wired.

## Cross-phase dependency notes

- **Phase 1 is the unlock.** The gateway's data/query/security *logic* is already driver-agnostic (the smoke tests run the full pipeline on `sqlite::memory:`); only the deployment plumbing — `Connection` DSN, `schema/*.sql` DDL, and the installer — is MySQL-bound. Phase 1 closes that gap and adds the app-definition model.
- **Service Registry (2nd) and Demo 4a (3rd) both depend only on Phase 1**, not on each other — they could run in parallel. The chosen order front-loads architectural risk reduction; swap them if demo urgency rises. Phase 3 is validated by its **own** reference handlers + tests, not by 4a (4a uses no service operations).
- **Demo 4b** needs the Service Registry (Phase 2 in this order).
- **The app-definition manifest is the shared seed** consumed by config, installer, admin, and every demo app.

## Three decisions that gate downstream work

1. **App-definition layering (Phase 1.5)** — the manifest is an orchestration *index* (entity names, service names), **not** a god-file; full entity policies live in referenced per-entity definitions, and services map to the `OperationRegistry` allowlist. Keep the MVP minimal; defer the "marketplace." See [Phase 1 §Application Definition Framework](phase-1-configuration-management.md#application-definition-framework-phase-15).
2. **Service Registry handler allowlisting** — `operation → handler class` resolution must use a fixed, namespaced allowlist, never arbitrary class instantiation from registry strings. The code-execution mirror of the SQL-identifier discipline. See [Phase 3 §Security](phase-3-service-registry.md#security-handler-resolution-must-be-allowlisted).
3. **Admin authentication** — an always-on page that manages clients/permissions/entities is root over the gateway and contradicts the installer's "self-lock and delete" posture. Recommended model (CLI-first + localhost-only web view) must be ratified before Phase 4 (Admin) begins. See [Phase 2 §Security](phase-2-admin-setup.md#security-model-the-gating-decision).

## Conventions used in these plans

- **Status legend:** Planned · In progress · Done · Deferred.
- **Decision blocks** are marked **DECISION** with a recommended option and any open question.
- **Security invariants** carried from the core gateway: registry-allowlisted identifiers, parameter-bound values, no raw SQL / no arbitrary class names, fail-closed on exposure, least privilege per client.
- File references use repo-relative paths so they stay clickable.
