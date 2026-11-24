// Agents page: list + switch + delete + AgentFather side panel + deploy steps + event log.

type AgentState = 'starting' | 'active' | 'stopping' | 'inactive' | 'failed';
type JobType = 'create' | 'enable' | 'disable' | 'delete';
type Runtime = 'openclaw' | 'hermes';

const RUNTIME_LABEL: Record<Runtime, string> = {openclaw: 'OpenClaw', hermes: 'Hermes Agent'};
const MODEL_LABEL: Record<string, string> = {
  'claude-sonnet-5-5': 'Claude Sonnet 5.5', 'claude-opus-5-5': 'Claude Opus 5.5',
  'claude-haiku-5-5': 'Claude Haiku 5.5', 'claude-sonnet-4-6': 'Claude Sonnet 4.6',
};

interface Agent {
  id: string;
  name: string;
  job: string;
  channel_type: 'telegram' | 'whatsapp';
  channel_account: string;
  enabled: boolean;
  runtime: Runtime;
  model: string;
  restricted: boolean;
  state: AgentState;
  state_reason: string | null;
  last_message_at: string | null;
  created_at: string;
  busy: boolean;
  pending_job: {type: JobType; state: string; step: string | null} | null;
}

interface AgentEvent {
  timestamp: string;
  type: string;
  state: string | null;
  result: string | null;
  duration_ms: number | null;
  job_id: string | null;
}

interface AgentDetail extends Agent {
  events: AgentEvent[];
}

interface ChatMessage {
  role: 'user' | 'assistant';
  content: string;
}

interface AgentConfig {
  name: string;
  job: string;
  instructions: string;
  prohibited_tasks: string[];
  channel_type: 'telegram' | 'whatsapp';
  runtime: Runtime;
  model: string;
  allowed_users: string[];
}

interface Question {
  id: string;
  kind: 'single' | 'multi' | 'text';
  options: string[];
  other: boolean;
  placeholder: string;
  allow_empty?: boolean;
}

interface Session {
  id: string;
  messages: ChatMessage[];
  status: 'open' | 'confirmed' | 'cancelled';
  question: Question | null;
  complete: boolean;
  config: AgentConfig | null;
  validation_error: string | null;
  error: string | null;
}

interface Answer {
  text?: string;
  choices?: string[];
  action?: 'change';
}

type Side =
  | {mode: 'chat'; session: Session | null; busy: boolean; error: string | null; picked: Set<string>}
  | {mode: 'deploy' | 'log' | 'qr'; agentId: string};

// qrcode-generator@1.4.4 (loaded from /qrcode.js, bundled at build time).
declare function qrcode(typeNumber: number, errorCorrectionLevel: 'L' | 'M' | 'Q' | 'H'): {
  addData(data: string): void;
  make(): void;
  createSvgTag(cellSize?: number, margin?: number): string;
};

function qrSvg(url: string): string {
  const qr = qrcode(0, 'M');
  qr.addData(url);
  qr.make();
  return qr.createSvgTag(6, 2);
}

/** Scanning https://t.me/<bot> opens the chat with that bot directly in Telegram. */
function telegramLink(account: string): string | null {
  const user = account.replace(/^@/, '');
  return /^[A-Za-z0-9_]{5,32}$/.test(user) ? `https://t.me/${user}` : null;
}

function renderQr(a: Agent): string {
  const link = telegramLink(a.channel_account);
  if (!link) return '<p class="muted">No Telegram bot linked to this agent.</p>';
  const note = a.state === 'active' ? '' : `<p class="error">${esc(a.name)} is ${STATE_LABEL[a.state]}: it will not answer until it is Active.</p>`;
  return `<div class="qr">${qrSvg(link)}
    <p>Scan with your phone camera to open the chat with <b>${esc(a.channel_account)}</b>, then tap <b>Start</b>.</p>
    <a class="btn" href="${esc(link)}" target="_blank" rel="noopener">Open in Telegram</a>${note}</div>`;
}

const $ = <T extends HTMLElement = HTMLElement>(sel: string): T => {
  const el = document.querySelector<T>(sel);
  if (!el) throw new Error(`Missing element ${sel}`);
  return el;
};

const esc = (s: unknown): string =>
  String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'})[c] as string);

async function api<T>(path: string, opts: {method?: string; body?: unknown} = {}): Promise<T> {
  const r = await fetch('/api' + path, {
    method: opts.method ?? 'GET',
    headers: {'content-type': 'application/json'},
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
  });
  if (r.status === 401) {
    location.href = '/login';
    throw new Error('Not signed in');
  }
  const data = (await r.json().catch(() => ({}))) as T & {detail?: string};
  if (!r.ok) throw new Error(data.detail ?? `Request failed (${r.status})`);
  return data;
}

const STATE_LABEL: Record<AgentState, string> = {
  starting: 'Starting', active: 'Active', stopping: 'Stopping', inactive: 'Inactive', failed: 'Failed',
};
const STEP_LABEL: Record<string, string> = {
  validate_config: 'Validate configuration',
  create_container: 'Create isolated container',
  start_and_health_check: 'Start runtime and health check',
  rollback: 'Clean up after failure',
};
const DEPLOY_STEPS = ['validate_config', 'create_container', 'start_and_health_check'];

let agents: Agent[] = [];
let side: Side | null = null;

function timeAgo(iso: string | null): string {
  if (!iso) return '<span class="muted">—</span>';
  const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return `${Math.max(s, 0)}s ago`;
  if (s < 3600) return `${Math.round(s / 60)} min ago`;
  if (s < 86400) return `${Math.round(s / 3600)} h ago`;
  return new Date(iso).toLocaleString();
}

function renderAgents(): void {
  $('#empty').hidden = agents.length > 0;
  $('#table thead').hidden = agents.length === 0;
  const selected = side && side.mode !== 'chat' ? side.agentId : null;
  $('#rows').innerHTML = agents.map((a) => {
    const locked = a.busy || a.state === 'starting' || a.state === 'stopping';
    return `
    <tr data-id="${a.id}" class="${selected === a.id ? 'selected' : ''}">
      <td><div class="a-name">${esc(a.name)}</div><div class="a-job" title="${esc(a.job)}">${esc(a.job)}</div>
          <div class="a-meta">${RUNTIME_LABEL[a.runtime] ?? a.runtime} · ${esc(MODEL_LABEL[a.model] ?? a.model)}${a.restricted ? ' · 🔒 allowlist' : ''}</div></td>
      <td class="chan">${esc(a.channel_account || '—')}<small>${a.channel_type === 'telegram' ? 'Telegram' : 'WhatsApp'}</small></td>
      <td><span class="badge ${a.state}">${STATE_LABEL[a.state] ?? a.state}</span>
          ${a.state === 'failed' && a.state_reason ? `<div class="reason">${esc(a.state_reason)}</div>` : ''}</td>
      <td>${timeAgo(a.last_message_at)}</td>
      <td><button class="switch" role="switch" aria-label="Turn ${esc(a.name)} ${a.enabled ? 'off' : 'on'}"
           aria-checked="${a.enabled}" data-act="switch" ${locked ? 'disabled' : ''}></button></td>
      <td><div class="actions">
        ${a.channel_type === 'telegram' && a.channel_account ? '<button class="btn small" data-act="qr">QR</button>' : ''}
        <button class="btn small" data-act="log">Log</button>
        <button class="btn small" data-act="delete" ${a.busy ? 'disabled' : ''}>Delete</button>
      </div></td>
    </tr>`;
  }).join('');
}

async function refresh(): Promise<void> {
  try {
    agents = await api<Agent[]>('/agents');
    renderAgents();
  } catch (e) {
    console.error(e);
  }
  if (side && side.mode !== 'chat') void renderAgentSide();
}

// ---------------------------------------------------------------- side panel
function openSide(title: string): void {
  $('#side').hidden = false;
  $('#side-title').textContent = title;
}

function closeSide(): void {
  if (side && side.mode === 'chat' && side.session?.status === 'open') {
    api(`/agentfather/sessions/${side.session.id}/cancel`, {method: 'POST'}).catch(() => undefined);
  }
  side = null;
  $('#side').hidden = true;
  $('#composer').hidden = true;
  renderAgents();
}

async function startChat(): Promise<void> {
  const chat: Side = {mode: 'chat', session: null, busy: false, error: null, picked: new Set()};
  side = chat;
  openSide('AgentFather');
  $('#side-body').innerHTML = '<div class="msg assistant typing">AgentFather is joining…</div>';
  try {
    chat.session = await api<Session>('/agentfather/sessions', {method: 'POST'});
    renderChat();
  } catch (e) {
    $('#side-body').innerHTML = `<p class="error">${esc((e as Error).message)}</p>`;
  }
}

function renderSummary(c: AgentConfig): string {
  const tasks = c.prohibited_tasks.length
    ? '<ul>' + c.prohibited_tasks.map((t) => `<li>${esc(t)}</li>`).join('') + '</ul>'
    : '<span class="muted">None</span>';
  const telegram = c.channel_type === 'telegram';
  return `<div class="summary" id="summary">
    <h3>Configuration summary</h3>
    <dl>
      <dt>Name</dt><dd>${esc(c.name)}</dd>
      <dt>Job</dt><dd>${esc(c.job)}</dd>
      <dt>Instructions</dt><dd>${esc(c.instructions)}</dd>
      <dt>Prohibited</dt><dd>${tasks}</dd>
      <dt>Runtime</dt><dd>${RUNTIME_LABEL[c.runtime]}</dd>
      <dt>Model</dt><dd>${esc(MODEL_LABEL[c.model] ?? c.model)}</dd>
      <dt>Channel</dt><dd>${telegram ? 'Telegram' : 'WhatsApp'}</dd>
      <dt>Access</dt><dd>${c.allowed_users.length ? 'Only: ' + c.allowed_users.map(esc).join(', ') : 'Anyone with the QR code or link'}</dd>
      <dt>Tools</dt><dd>None (default deny)</dd>
    </dl>
    ${telegram
      ? `<label>Telegram bot token
           <small>Create a bot with @BotFather and paste its token. It goes straight to the secrets store; AgentFather never sees it.</small>
           <div class="helper">${qrSvg('https://t.me/BotFather')}<span>Scan to open <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>, send <b>/newbot</b>, pick a name and a username ending in “bot”, then copy the token here.</span></div>
           <input id="tg-token" type="password" autocomplete="off" spellcheck="false" placeholder="123456789:AA…"></label>`
      : '<p class="error">WhatsApp is not available in this POC yet. Click Change and pick Telegram.</p>'}
    <p class="error" id="confirm-err" hidden></p>
    <div class="row-btns"><button class="btn" id="change">Change</button>
      <button class="btn primary" id="confirm" ${telegram ? '' : 'disabled'}>Confirm</button></div>
  </div>`;
}

function renderChat(): void {
  if (!side || side.mode !== 'chat' || !side.session) return;
  const s = side.session;
  let html = s.messages.map((m) => `<div class="msg ${m.role}">${esc(m.content)}</div>`).join('');
  const q = s.question;
  if (q && s.status === 'open' && !side.busy && q.kind !== 'text') {
    const picked = side.picked;
    html += `<div class="choices ${q.kind}">` + q.options.map((o) =>
      `<button class="chip ${picked.has(o) ? 'on' : ''}" data-choice="${esc(o)}">${q.kind === 'multi' ? (picked.has(o) ? '☑ ' : '☐ ') : ''}${esc(o)}</button>`,
    ).join('') + (q.kind === 'multi'
      ? `<button class="btn primary small" id="continue">${picked.size || !q.allow_empty ? 'Continue' : 'None of these'}</button>` : '') + '</div>';
  }
  if (side.busy) html += '<div class="msg assistant typing">AgentFather is typing…</div>';
  if (side.error) html += `<p class="error">${esc(side.error)}</p>`;
  if (s.complete && s.config) html += renderSummary(s.config);
  else if (s.validation_error) html += `<p class="error">The platform rejected this configuration: ${esc(s.validation_error)}</p>`;
  const body = $('#side-body');
  body.innerHTML = html;
  const showInput = s.status === 'open' && !!q && (q.kind === 'text' || q.other);
  $('#composer').hidden = !showInput;
  if (q) $<HTMLInputElement>('#msg').placeholder = q.placeholder || 'Type your answer…';
  body.scrollTop = body.scrollHeight;
  if (showInput) $<HTMLInputElement>('#msg').focus();
}

async function sendAnswer(answer: Answer): Promise<void> {
  if (!side || side.mode !== 'chat' || !side.session || side.busy) return;
  const chat = side;
  const session = chat.session as Session;
  chat.busy = true;
  chat.error = null;
  renderChat();
  try {
    const next = await api<Session>(`/agentfather/sessions/${session.id}/messages`, {method: 'POST', body: answer});
    chat.session = next;
    chat.error = next.error;
    if (!next.error) chat.picked = new Set();
  } catch (e) {
    chat.error = (e as Error).message;
  }
  chat.busy = false;
  renderChat();
}

async function confirmConfig(): Promise<void> {
  if (!side || side.mode !== 'chat' || !side.session) return;
  const btn = $<HTMLButtonElement>('#confirm');
  const err = $('#confirm-err');
  const tokenInput = document.querySelector<HTMLInputElement>('#tg-token');
  btn.disabled = true;
  err.hidden = true;
  try {
    const agent = await api<Agent>(`/agentfather/sessions/${side.session.id}/confirm`, {
      method: 'POST', body: {telegram_token: tokenInput ? tokenInput.value : ''},
    });
    side = {mode: 'deploy', agentId: agent.id};
    $('#composer').hidden = true;
    openSide(`Deploying ${agent.name}`);
    await refresh();
  } catch (e) {
    err.textContent = (e as Error).message;
    err.hidden = false;
    btn.disabled = false;
  }
}

function renderDeploy(a: AgentDetail): string {
  const queued = a.events.find((e) => e.type === 'job_create_queued');
  const evs = queued ? a.events.filter((e) => e.job_id === queued.job_id).reverse() : [];
  const byStep = new Map(evs.map((e) => [e.type, e]));
  const running = a.pending_job?.type === 'create' ? a.pending_job.step : null;
  let html = '<ol class="steps">' + DEPLOY_STEPS.map((st) => {
    const e = byStep.get(st);
    const cls = e?.state ?? (running === st ? 'running' : '');
    const mark = cls === 'completed' ? '✓' : cls === 'failed' ? '!' : cls === 'running' ? '…' : '';
    return `<li class="${cls}"><span class="dot">${mark}</span><div>${STEP_LABEL[st]}
      ${e?.duration_ms != null ? `<div class="detail">${(e.duration_ms / 1000).toFixed(1)}s</div>` : ''}
      ${e?.result ? `<div class="detail">${esc(e.result)}</div>` : ''}</div></li>`;
  }).join('');
  const rb = byStep.get('rollback');
  if (rb) html += `<li class="${rb.state ?? ''}"><span class="dot">↺</span><div>${STEP_LABEL.rollback}</div></li>`;
  html += '</ol>';
  if (a.state === 'active') html += `<p>✅ <b>${esc(a.name)}</b> is Active.</p>` + renderQr(a);
  if (a.state === 'failed') html += `<p class="error">Failed: ${esc(a.state_reason ?? 'unknown reason')}</p>`;
  return html;
}

function renderLog(a: AgentDetail): string {
  const rows = a.events.map((e) => `
    <div><span class="t">${new Date(e.timestamp).toLocaleTimeString()}</span>
    <span class="${e.state === 'failed' ? 'failed' : ''}">${esc(e.type)}${e.state ? ' · ' + esc(e.state) : ''}${e.duration_ms != null ? ` · ${e.duration_ms} ms` : ''}${e.result ? ' — ' + esc(e.result) : ''}</span></div>`);
  return `<div class="log">${rows.join('') || '<span class="muted">No events yet.</span>'}</div>`;
}

async function renderAgentSide(): Promise<void> {
  if (!side || side.mode === 'chat') return;
  const {agentId, mode} = side;
  let a: AgentDetail;
  try {
    a = await api<AgentDetail>(`/agents/${agentId}`);
  } catch (e) {
    $('#side-body').innerHTML = `<p class="error">${esc((e as Error).message)}</p>`;
    return;
  }
  if (!side || side.mode !== mode || side.agentId !== agentId) return;
  const html = mode === 'deploy' ? renderDeploy(a) : mode === 'qr' ? renderQr(a) : renderLog(a);
  const body = $('#side-body');
  if (body.innerHTML !== html) body.innerHTML = html; // avoid flicker on the 2 s refresh
}

// ---------------------------------------------------------------- events
$('#create').addEventListener('click', () => void startChat());
$('#close').addEventListener('click', closeSide);
$('#logout').addEventListener('click', async () => {
  await api('/logout', {method: 'POST'});
  location.href = '/login';
});
$('#composer').addEventListener('submit', (e) => {
  e.preventDefault();
  const input = $<HTMLInputElement>('#msg');
  const text = input.value.trim();
  const q = side?.mode === 'chat' ? side.session?.question : null;
  if (!text && q?.kind !== 'multi') return;
  input.value = '';
  if (q?.kind === 'multi' && side?.mode === 'chat') void sendAnswer({choices: [...side.picked], text});
  else void sendAnswer({text});
});
$('#side-body').addEventListener('click', (e) => {
  const el = e.target as HTMLElement;
  if (el.id === 'confirm') void confirmConfig();
  if (el.id === 'change') void sendAnswer({action: 'change'});
  if (!side || side.mode !== 'chat') return;
  const chat = side;
  const q = chat.session?.question;
  const choice = el.closest<HTMLElement>('[data-choice]')?.dataset.choice;
  if (q && choice !== undefined) {
    if (q.kind === 'single') {
      void sendAnswer({choices: [choice]});
    } else {
      if (chat.picked.has(choice)) chat.picked.delete(choice);
      else chat.picked.add(choice);
      renderChat();
    }
  }
  if (el.id === 'continue') {
    const input = $<HTMLInputElement>('#msg');
    const text = input.value.trim();
    input.value = '';
    void sendAnswer({choices: [...chat.picked], text});
  }
});
$('#rows').addEventListener('click', async (e) => {
  const btn = (e.target as HTMLElement).closest<HTMLButtonElement>('[data-act]');
  const row = btn?.closest<HTMLTableRowElement>('tr');
  const a = agents.find((x) => x.id === row?.dataset.id);
  if (!btn || !a) return;
  switch (btn.dataset.act) {
    case 'switch':
      btn.disabled = true;
      try {
        await api(`/agents/${a.id}/switch`, {method: 'POST', body: {enabled: !a.enabled}});
      } catch (err) {
        alert((err as Error).message);
      }
      void refresh();
      break;
    case 'qr':
    case 'log':
      if (side?.mode === 'chat') closeSide();
      side = {mode: btn.dataset.act, agentId: a.id};
      openSide(btn.dataset.act === 'qr' ? `Chat with ${a.name}` : `Event log · ${a.name}`);
      $('#composer').hidden = true;
      renderAgents();
      void renderAgentSide();
      break;
    case 'delete': {
      $('#del-name').textContent = a.name;
      const dlg = $<HTMLDialogElement>('#confirm-del');
      dlg.returnValue = '';
      dlg.showModal();
      dlg.addEventListener('close', async () => {
        if (dlg.returnValue !== 'ok') return;
        try {
          await api(`/agents/${a.id}`, {method: 'DELETE'});
        } catch (err) {
          alert((err as Error).message);
        }
        void refresh();
      }, {once: true});
      break;
    }
  }
});

void (async () => {
  const me = await api<{email: string}>('/me');
  $('#me').textContent = me.email;
  await refresh();
  setInterval(() => void refresh(), 2000);
})();
