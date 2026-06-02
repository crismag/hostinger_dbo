# Phase 4 — Demo App (`demo-ticketdesk`)

**Status:** Planned · **Lives in `apps/`** (versioned with the repo, not part of the gateway package)
**Delivery:** 4a → `v0.4.0` (3rd, right after the config foundation) · 4b → `v0.6.0` (5th, after the Service Registry)
**Depends on:** Phase 1 for 4a; Service Registry for 4b. Setup is via the **installer + app-definition manifest** (Phase 1), not the Admin page — Admin ships later (`v0.5.0`) as an alternative management path.

## Goal

A small, responsive, no-framework web app that proves the gateway supports real application-style database work on **SQLite** — CRUD, equality filters, LIKE search, sorting, pagination, GROUP BY/aggregates, dashboard widgets, and reactive UI updates — and (in 4b) reports via service operations. It doubles as living documentation: every UI action shows the request it sent and the response it got.

## Split for early delivery

- **4a — Foundation & generic features** (buildable right after Phase 1): SQLite setup, registered entities, CRUD + query controls, dashboard widgets via GROUP BY/aggregates, reactive UI, request/response panel.
- **4b — Reports & complex actions** (needs Phase 3): dashboards/reports backed by named service operations (joins, multi-table summaries, a transactional "create ticket + first comment" flow).

## Architecture (BFF — the integration story)

```
Browser (app.js)
   │  same-origin fetch, no secret in the browser
   ▼
apps/demo-ticketdesk/public/ + src/   ← the "application": holds the HMAC secret,
   │                                     SIGNS requests, forwards to the gateway
   ▼  signed POST /api/v1/...
php-dbo-gateway  →  SQLite (storage/demo.sqlite)
```

The demo's PHP layer is the reference example of *an application using the gateway as its data layer*. The browser never holds the HMAC secret; the demo backend signs every call. This is also why the gateway's security model stays intact — the browser talks to a trusted same-origin backend, not the gateway directly.

## Folder structure

```
apps/demo-ticketdesk/
├── README.md                 # what it shows, how to run
├── public/
│   ├── index.php             # demo BFF entry / static host
│   ├── api.php               # signs + proxies to the gateway (CRUD/query)
│   ├── reports.php           # (4b) calls service operations
│   └── assets/ app.js app.css
├── src/                      # GatewayClient (HMAC signer), config
├── data/
│   ├── schema.sql            # SQLite object tables (tickets, customers, agents, comments)
│   ├── seed.sql              # realistic demo data
│   └── registry.json         # api_entities policies (fields/insertable/searchable/groupable/aggregatable)
└── docs/ walkthrough.md api-examples.md query-examples.md
```

## Data model

| Table | Purpose | Notable for the demo |
| --- | --- | --- |
| `customers` | requesters | LIKE on name/email; group source |
| `agents` | support staff | assignment; group-by owner |
| `tickets` | id, subject, body, status, priority, customer_id, agent_id, created_at, updated_at | CRUD; LIKE on subject/body; GROUP BY status/priority; counts |
| `comments` | ticket thread | (4b) transactional create-with-first-comment |

Registry (`api_entities`) marks: `searchable` = subject/body/name/email; `groupable` = status/priority/agent_id; `aggregatable` = id (counts) + any numeric (e.g. a future `sla_hours`).

## Feature → demonstration mapping

| Gateway feature | UI panel | Behind it |
| --- | --- | --- |
| CRUD | Tickets list + create/edit/delete | `select/insert/update/delete` |
| Equality filter | Status/priority dropdowns | `where` |
| LIKE search | Search box | `filters: [{field, op:like}]` |
| Sort / paginate | Column headers / pager | `order_by`,`order_dir`,`limit`,`offset` |
| GROUP BY + aggregates | Dashboard cards (open/closed counts, by-priority) | `group_by` + `count` |
| Reactive updates | After create/edit/delete → refresh list + cards + toast | re-fetch on mutation |
| Raw request/response | A panel showing the exact gateway request + JSON response per action | teaching aid (gateway request, **not** SQL) |
| **Reports (4b)** | Revenue/SLA/agent-load report views | service operations (joins/aggregates) |

> Note on the "Raw Query Viewer" from the original brief: the gateway never exposes SQL by design. The demo's teaching panel shows the **gateway request and response** (the honest, product-accurate artifact), not generated SQL.

## Setup flow (installer + app manifest — Phase 1)

The demo ships an `app.json` (app-definition manifest) and is stood up by the installer, **not** the Admin page (Admin ships later, `v0.5.0`). This deliberately proves the manifest/CLI path is sufficient.

1. Choose the `demo` profile (Phase 1) → SQLite driver, `public_demo` allowed, dev-friendly.
2. `bin/install.php --app apps/demo-ticketdesk/app.json` → create `storage/ticketdesk.sqlite`, load `data/schema.sql`, register the manifest's entities from their `data/registry.json` policies, create a `demo-app` client with scoped CRUD + select (and, in 4b, service) grants, seed `data/seed.sql`.
3. The demo backend reads the `demo-app` secret from its own config to sign requests.
4. Once Admin (`v0.5.0`) exists, it becomes an alternative way to manage the same app.

## Implementation plan

**4a**
1. `apps/demo-ticketdesk/data/` — SQLite `schema.sql`, `seed.sql`, `registry.json`.
2. `src/GatewayClient.php` — HMAC signer (reuse the canonical-string logic; mirror `SignatureVerifier`), thin `request(method, path, body)`.
3. `public/api.php` — same-origin endpoints the UI calls (`/api.php?op=list&entity=tickets&…`), which validate/translate to a signed gateway call and relay the JSON. Holds no business logic beyond shaping.
4. `public/assets/app.js` — no-framework reactive UI: fetch → render table/cards → re-fetch on mutation → toast; the request/response panel.
5. `app.css` — responsive layout (left nav, content, dashboard cards).
6. `README.md` + `docs/walkthrough.md`.

**4b**
7. Reference service operations under the gateway (`src/Services/Operations/reports/…`) for ticket/agent summaries + a transactional create-ticket-with-comment.
8. `public/reports.php` — signs + calls `/api/v1/services/reports/…`; report views in the UI.

## Security considerations

- The HMAC secret lives only in the demo backend config, never shipped to the browser.
- The demo backend is a thin, fixed proxy — it does not accept arbitrary entity/field names from the browser beyond what its own UI uses; it maps UI intents to known gateway calls.
- If hosted publicly, the `demo` profile + gateway rate limits + (optional) gateway `public_demo` constraints apply; seed data only.
- `storage/demo.sqlite` stays outside the docroot, `0600`.

## Test plan / Definition of Done

**4a**
- [ ] Installer/admin can stand up the demo (SQLite, entities, client, seed) in one documented flow.
- [ ] Each UI panel performs the correct signed gateway call; mutations reactively refresh list + dashboard.
- [ ] LIKE search, sort, pagination, and GROUP BY cards return correct results from seed data.
- [ ] Request/response panel shows the real gateway exchange.
- [ ] Responsive on mobile/desktop; no framework dependency.

**4b**
- [ ] Report views render from service operations (joins/aggregates) with correct numbers.
- [ ] Transactional create-ticket-with-comment commits both or neither.

- [ ] `docs/walkthrough.md`, `api-examples.md`, `query-examples.md` complete; CHANGELOG note.
