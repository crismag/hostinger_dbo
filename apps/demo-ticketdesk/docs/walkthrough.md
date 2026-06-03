# TicketDesk Walkthrough

A guided tour of the demo and the gateway features each step exercises. Keep the
**Gateway panel** (top-right toggle) open — it shows the exact signed request the
BFF sent and the gateway's JSON response for every action.

## Setup

```bash
php apps/demo-ticketdesk/setup.php          # build DB, register entities, seed, create client
php -S 127.0.0.1:8000 -t public public/index.php          # gateway
php -S 127.0.0.1:8001 -t apps/demo-ticketdesk/public      # demo
# open http://127.0.0.1:8001/
```

## 1. Dashboard — GROUP BY + aggregates

On load, the cards show counts per status and the urgent count per priority. Open
the panel: the request is

```json
{ "group_by": ["status"], "aggregates": [{"fn":"count","field":"id","as":"n"}], "order_by":"status" }
```

to `POST /api/v1/tickets/select`. This is a single-entity aggregate — no raw SQL,
no JOIN. The gateway returns one row per status with the count.

## 2. List, filter, sort, paginate

The table is a `select` with a field allowlist. Use the **status** and
**priority** dropdowns (equality `where`), the **sort** menu (`order_by`/
`order_dir`), and **Prev/Next** (`limit`/`offset`). Each change re-issues a
signed `select`; watch the panel update.

## 3. Search — LIKE

Type `Login` in the search box. The BFF sends

```json
{ "filters": [{"field":"subject","op":"like","value":"%Login%"}], ... }
```

`subject` is in the entity's `searchable` allowlist, so the gateway permits the
`like` operator; the value is bound as a parameter (no injection). You'll get the
three "Login…" tickets.

## 4. Create — insert (+ reactive refresh)

Click **+ New ticket**, fill the form, Save. The BFF sends an `insert`; the
gateway returns `201` with the new id. The app then re-fetches the list **and**
the dashboard, and a toast confirms — demonstrating live interaction with the
database. Create an *urgent* ticket and watch the Urgent card increment.

## 5. Edit / advance / delete — update & delete

- **edit** opens the dialog and sends an `update` (filtered by primary key).
- **advance** cycles `open → pending → closed` via a one-field `update`.
- **del** sends a `delete` filtered by `id`.

All mutations filter by the primary key, so the gateway's **mutation guard** is
satisfied (try imagining a `delete` with no `where` — the gateway would reject it
with `RESTRICTIVE_WHERE_REQUIRED`).

## 6. The security story

- The **HMAC secret never reaches the browser**. Open devtools → Network: the
  browser only calls `api.php`; the signing happens server-side in the BFF.
- Every call is **audited** in the gateway (`api_audit_logs` in
  `storage/ticketdesk.sqlite`).
- The gateway only ever sees **allowlisted** entities, fields, filters, and
  operators — the demo cannot ask for anything the registry didn't permit.

## 7. Service operations (4b)

The generic gateway is single-entity, so reports and transactions live in **named
service operations** (`POST /api/v1/services/{service}/{operation}`). The demo
client is granted two:

**Agent workload report** (`tickets.agent_workload`) — the "Agent workload" table
at the bottom of the page. It's a **JOIN** of `agents` + `tickets` with
conditional aggregates (open/pending/total per agent) — impossible through the
single-entity gateway. Open the panel: the request goes to
`/api/v1/services/tickets/agent_workload`. The handler's SQL is committed code;
the caller sends no SQL.

**Transactional create** (`tickets.create_with_comment`) — in the **New ticket**
dialog, fill the **First comment** field. The app then routes through the service
operation instead of a plain `insert`: the handler inserts the ticket **and** its
first comment inside a single transaction. If either write fails, both roll back
(the test suite proves a forced failure leaves no ticket behind). Leave the
comment blank and it's a plain single-entity `insert`.

Both are gateway-side, allowlisted handlers (`OperationRegistry`) — see
[docs/service-authoring.md](../../../docs/service-authoring.md) for how they're written.
