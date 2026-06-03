'use strict';
// TicketDesk demo UI — no framework. Talks only to the same-origin BFF (api.php),
// which signs requests to php-dbo-gateway. Every call updates the gateway panel.

const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const state = {
  search: '', status: '', priority: '',
  order_by: 'created_at', order_dir: 'desc',
  offset: 0, limit: 10,
  custMap: {}, agentMap: {}, rows: [],
};

async function call(op, body) {
  const res = await fetch('api.php?op=' + op, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}),
  });
  const json = await res.json().catch(() => ({}));
  if (json.gateway) showPanel(json.gateway);
  return json;
}

function showPanel(ex) {
  $('#panel-req').textContent = JSON.stringify(ex.request, null, 2);
  $('#panel-res').textContent = JSON.stringify(ex.response, null, 2);
  const pill = $('#panel-status');
  pill.textContent = 'HTTP ' + ex.status;
  pill.className = 'pill ' + (ex.status >= 200 && ex.status < 300 ? 'ok' : 'no');
}

function toast(msg, ok = true) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'toast show ' + (ok ? 'ok' : 'err');
  setTimeout(() => { t.className = 'toast'; }, 2200);
}

async function loadLookups() {
  const r = await call('lookups');
  const cs = (r.data && r.data.customers) || [];
  const as = (r.data && r.data.agents) || [];
  state.custMap = {}; state.agentMap = {};
  cs.forEach((c) => (state.custMap[c.id] = c.name));
  as.forEach((a) => (state.agentMap[a.id] = a.name));
  $('#sel-customer').innerHTML = '<option value="">—</option>' + cs.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
  $('#sel-agent').innerHTML = '<option value="">—</option>' + as.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
}

async function loadDashboard() {
  const r = await call('dashboard');
  const st = {}, pr = {};
  ((r.data && r.data.by_status) || []).forEach((x) => (st[x.status] = +x.n));
  ((r.data && r.data.by_priority) || []).forEach((x) => (pr[x.priority] = +x.n));
  const total = Object.values(st).reduce((a, b) => a + b, 0);
  setCard('total', total); setCard('open', st.open || 0); setCard('pending', st.pending || 0);
  setCard('closed', st.closed || 0); setCard('urgent', pr.urgent || 0);
}
function setCard(k, v) { const el = document.querySelector(`[data-card="${k}"]`); if (el) el.textContent = v; }

async function loadList() {
  const r = await call('list', {
    search: state.search, status: state.status, priority: state.priority,
    order_by: state.order_by, order_dir: state.order_dir, offset: state.offset, limit: state.limit,
  });
  const rows = (r.data && r.data.tickets) || [];
  state.rows = rows;
  const tb = $('#rows');
  tb.innerHTML = rows.length ? rows.map((t) => `
    <tr>
      <td>${t.id}</td>
      <td>${esc(t.subject)}</td>
      <td>${esc(state.custMap[t.customer_id] || '—')}</td>
      <td>${esc(state.agentMap[t.agent_id] || '—')}</td>
      <td><span class="badge s-${esc(t.status)}">${esc(t.status)}</span></td>
      <td><span class="badge p-${esc(t.priority)}">${esc(t.priority)}</span></td>
      <td class="act">
        <button data-edit="${t.id}" class="mini">edit</button>
        <button data-cycle="${t.id}" class="mini">advance</button>
        <button data-del="${t.id}" class="mini danger">del</button>
      </td>
    </tr>`).join('') : '<tr><td colspan="7" class="muted">No tickets match.</td></tr>';
  $('#page-info').textContent = `offset ${state.offset} · ${rows.length} shown`;
  $('#prev').disabled = state.offset === 0;
  $('#next').disabled = rows.length < state.limit;
}

async function loadReports() {
  const r = await call('agent_workload');
  const rows = (r.data && r.data.agents) || [];
  document.querySelector('#report-rows').innerHTML = rows.length
    ? rows.map((a) => `<tr><td>${esc(a.agent)}</td><td>${a.open || 0}</td><td>${a.pending || 0}</td><td><strong>${a.total || 0}</strong></td></tr>`).join('')
    : '<tr><td colspan="4" class="muted">No agents.</td></tr>';
}

async function refresh() { await Promise.all([loadList(), loadDashboard(), loadReports()]); }

// ---- dialog (create / edit) ----
const dlg = $('#dlg');
function openDialog(ticket) {
  const f = $('#ticket-form');
  f.reset();
  $('#dlg-title').textContent = ticket ? `Edit ticket #${ticket.id}` : 'New ticket';
  f.id.value = ticket ? ticket.id : '';
  if (ticket) {
    f.subject.value = ticket.subject || '';
    f.body.value = ticket.body || '';
    f.customer_id.value = ticket.customer_id || '';
    f.agent_id.value = ticket.agent_id || '';
    f.status.value = ticket.status || 'open';
    f.priority.value = ticket.priority || 'normal';
  }
  dlg.showModal();
}

$('#ticket-form').addEventListener('submit', async (e) => {
  if (e.submitter && e.submitter.value === 'cancel') return;
  e.preventDefault();
  const f = e.target;
  const payload = {
    subject: f.subject.value, body: f.body.value,
    customer_id: f.customer_id.value, agent_id: f.agent_id.value,
    status: f.status.value, priority: f.priority.value,
    comment: f.comment.value,
  };
  let r, verb;
  if (f.id.value) { payload.id = +f.id.value; r = await call('update', payload); verb = 'updated'; }
  else if (payload.comment.trim() !== '') { r = await call('create_with_comment', payload); verb = 'created (transactional)'; }
  else { r = await call('create', payload); verb = 'created'; }
  const ok = r.gateway && r.gateway.status < 300;
  dlg.close();
  toast(ok ? 'Ticket ' + verb : 'Failed: ' + ((r.gateway && r.gateway.response.error && r.gateway.response.error.code) || 'error'), ok);
  await refresh();
});

// ---- delegated row actions ----
$('#rows').addEventListener('click', async (e) => {
  const b = e.target.closest('button'); if (!b) return;
  const t = state.rows.find((x) => x.id == (b.dataset.edit || b.dataset.cycle || b.dataset.del));
  if (b.dataset.edit) { openDialog(t); }
  else if (b.dataset.cycle) {
    const next = { open: 'pending', pending: 'closed', closed: 'open' }[t.status] || 'open';
    const r = await call('update', { id: t.id, status: next });
    toast(r.gateway && r.gateway.status < 300 ? `#${t.id} → ${next}` : 'Failed', r.gateway && r.gateway.status < 300);
    await refresh();
  } else if (b.dataset.del) {
    if (!confirm(`Delete ticket #${t.id}?`)) return;
    const r = await call('delete', { id: t.id });
    toast(r.gateway && r.gateway.status < 300 ? `#${t.id} deleted` : 'Failed', r.gateway && r.gateway.status < 300);
    await refresh();
  }
});

// ---- toolbar ----
let searchTimer;
$('#search').addEventListener('input', (e) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { state.search = e.target.value.trim(); state.offset = 0; loadList(); }, 250);
});
$('#f-status').addEventListener('change', (e) => { state.status = e.target.value; state.offset = 0; loadList(); });
$('#f-priority').addEventListener('change', (e) => { state.priority = e.target.value; state.offset = 0; loadList(); });
$('#f-sort').addEventListener('change', (e) => { const [o, d] = e.target.value.split(':'); state.order_by = o; state.order_dir = d; state.offset = 0; loadList(); });
$('#new-ticket').addEventListener('click', () => openDialog(null));
$('#prev').addEventListener('click', () => { state.offset = Math.max(0, state.offset - state.limit); loadList(); });
$('#next').addEventListener('click', () => { state.offset += state.limit; loadList(); });
$('#toggle-panel').addEventListener('click', () => document.body.classList.toggle('panel-hidden'));

// ---- boot ----
(async () => { await loadLookups(); await refresh(); })();
