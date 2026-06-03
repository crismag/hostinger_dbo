<?php

/**
 * TicketDesk demo — single-page shell. All data is loaded by app.js, which calls
 * the same-origin BFF (api.php); the BFF signs requests to php-dbo-gateway.
 */

declare(strict_types=1);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TicketDesk — php-dbo-gateway demo</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
  <div class="brand">🎫 TicketDesk <span class="muted">· php-dbo-gateway demo</span></div>
  <button id="toggle-panel" class="ghost">Gateway panel</button>
</header>

<main class="layout">
  <section class="content">
    <!-- Dashboard -->
    <h2>Dashboard</h2>
    <div id="cards" class="cards">
      <div class="card"><div class="card-n" data-card="total">–</div><div class="card-l">Total</div></div>
      <div class="card open"><div class="card-n" data-card="open">–</div><div class="card-l">Open</div></div>
      <div class="card pending"><div class="card-n" data-card="pending">–</div><div class="card-l">Pending</div></div>
      <div class="card closed"><div class="card-n" data-card="closed">–</div><div class="card-l">Closed</div></div>
      <div class="card urgent"><div class="card-n" data-card="urgent">–</div><div class="card-l">Urgent priority</div></div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <input id="search" type="search" placeholder="Search subject (LIKE)…">
      <select id="f-status"><option value="">Any status</option><option>open</option><option>pending</option><option>closed</option></select>
      <select id="f-priority"><option value="">Any priority</option><option>low</option><option>normal</option><option>high</option><option>urgent</option></select>
      <select id="f-sort">
        <option value="created_at:desc">Newest</option>
        <option value="created_at:asc">Oldest</option>
        <option value="priority:desc">Priority ↓</option>
        <option value="id:asc">ID ↑</option>
      </select>
      <button id="new-ticket" class="primary">+ New ticket</button>
    </div>

    <!-- Ticket list -->
    <table class="tickets">
      <thead><tr><th>#</th><th>Subject</th><th>Customer</th><th>Agent</th><th>Status</th><th>Priority</th><th></th></tr></thead>
      <tbody id="rows"><tr><td colspan="7" class="muted">Loading…</td></tr></tbody>
    </table>
    <div class="pager">
      <button id="prev" class="ghost">← Prev</button>
      <span id="page-info" class="muted"></span>
      <button id="next" class="ghost">Next →</button>
    </div>

    <!-- 4b: report backed by a service operation (JOIN + aggregates) -->
    <h2 style="margin-top:32px">Agent workload <span class="muted" style="font-size:13px">· service operation (JOIN)</span></h2>
    <table class="tickets">
      <thead><tr><th>Agent</th><th>Open</th><th>Pending</th><th>Total</th></tr></thead>
      <tbody id="report-rows"><tr><td colspan="4" class="muted">Loading…</td></tr></tbody>
    </table>
  </section>

  <!-- Gateway request/response teaching panel -->
  <aside id="panel" class="panel">
    <h3>Gateway exchange</h3>
    <p class="muted">The exact signed request the BFF sent and the gateway's JSON response. The browser never sees the HMAC secret.</p>
    <div class="kv"><span>Request</span></div>
    <pre id="panel-req">—</pre>
    <div class="kv"><span>Response</span> <span id="panel-status" class="pill">—</span></div>
    <pre id="panel-res">—</pre>
  </aside>
</main>

<!-- Create/edit dialog -->
<dialog id="dlg">
  <form id="ticket-form" method="dialog">
    <h3 id="dlg-title">New ticket</h3>
    <input type="hidden" name="id">
    <label>Subject</label><input name="subject" required maxlength="190">
    <label>Body</label><textarea name="body" rows="3"></textarea>
    <label>First comment <span class="muted">(optional — new tickets with a comment use the transactional service op)</span></label>
    <textarea name="comment" rows="2" placeholder="Leave blank for a plain insert"></textarea>
    <div class="row">
      <div><label>Customer</label><select name="customer_id" id="sel-customer"><option value="">—</option></select></div>
      <div><label>Agent</label><select name="agent_id" id="sel-agent"><option value="">—</option></select></div>
    </div>
    <div class="row">
      <div><label>Status</label><select name="status"><option>open</option><option>pending</option><option>closed</option></select></div>
      <div><label>Priority</label><select name="priority"><option>low</option><option selected>normal</option><option>high</option><option>urgent</option></select></div>
    </div>
    <menu>
      <button value="cancel" class="ghost">Cancel</button>
      <button value="save" class="primary" id="dlg-save">Save</button>
    </menu>
  </form>
</dialog>

<div id="toast" class="toast"></div>
<script src="assets/app.js"></script>
</body>
</html>
