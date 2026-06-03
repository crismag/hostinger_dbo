# TicketDesk — php-dbo-gateway demo (4a)

A small, responsive, no-framework support-ticket app that runs entirely on
**php-dbo-gateway** over **SQLite**. It demonstrates the gateway doing real
application work — CRUD, equality filters, LIKE search, sorting, pagination, and
`GROUP BY` dashboard widgets — without exposing raw SQL, and shows the exact
signed gateway exchange behind every click.

Includes **4a** (CRUD + generic query controls) and **4b** (reports and a
transactional flow via named **service operations**).

## Architecture

```
Browser (assets/app.js)             ← no HMAC secret here, ever
   │  same-origin fetch to api.php
   ▼
Demo BFF (public/api.php + src/)     ← holds the secret, SIGNS each request
   │  signed POST /api/v1/...
   ▼
php-dbo-gateway  →  SQLite (storage/ticketdesk.sqlite)
```

The browser only talks to the demo's own `api.php`. That BFF reads the demo
client's HMAC secret server-side, signs the request, and forwards it to the
gateway. Every UI action surfaces the signed request + gateway response in a
side panel, so the app doubles as living documentation.

## Run it

From the repository root:

```bash
# 1. One-command setup: creates the SQLite DB, registers entities from the
#    manifest, seeds demo data, and creates the demo client. (FORCE=1 rebuilds.)
php apps/demo-ticketdesk/setup.php

# 2. Start the gateway
php -S 127.0.0.1:8000 -t public public/index.php

# 3. Start the demo (separate terminal)
php -S 127.0.0.1:8001 -t apps/demo-ticketdesk/public

# 4. Open http://127.0.0.1:8001/
```

Override the gateway location with `GATEWAY_URL` (default `http://127.0.0.1:8000`).

## What it demonstrates

| UI | Gateway feature |
| --- | --- |
| Dashboard cards (Total / Open / Pending / Closed / Urgent) | `GROUP BY status` and `GROUP BY priority` with `count` aggregates |
| Ticket list | `select` with field allowlists |
| Search box | `filters` with `op: like` on `subject` |
| Status / priority dropdowns | equality `where` |
| Sort + Prev/Next | `order_by` / `order_dir` / `limit` / `offset` |
| New / Edit ticket | `insert` / `update` (PK-filtered, mutation-guard safe) |
| Delete | `delete` (PK-filtered) |
| "advance" | status cycle via `update` |
| **Agent workload** table | service operation `tickets.agent_workload` — a **JOIN** + conditional aggregates (4b) |
| New ticket + **first comment** | service operation `tickets.create_with_comment` — a **transaction** (4b) |
| Gateway panel | the exact signed request + JSON response per action |

After any mutation the list **and** dashboard reactively refresh.

## Files

```
apps/demo-ticketdesk/
├── app.json                  # manifest: driver, database, entities
├── setup.php                 # one-command install + seed
├── data/{schema,seed}.sql    # SQLite object tables + demo data
├── data/registry.json        # entity policies (fields/searchable/groupable/…)
├── src/GatewayClient.php      # HMAC signer + HTTP forwarder
├── src/bootstrap.php          # resolves gateway URL + client secret (server-side)
├── public/index.php           # SPA shell
├── public/api.php             # BFF: UI intents → signed gateway calls
└── public/assets/{app.js,app.css}
```

See [docs/walkthrough.md](docs/walkthrough.md) for a guided tour.
