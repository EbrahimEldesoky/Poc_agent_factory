<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
/* Agent Factory API. The web app talks only to this. No Docker socket, no secrets store. */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/jobs.php';
require_once __DIR__ . '/../src/agentfather.php';

const COOKIE = 'af_session';

final class HttpError extends RuntimeException {}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $code, string $detail): never
{
    throw new HttpError($detail, $code);
}

/** Request body as an array. Bad input never echoes submitted values back (they may hold a token). */
function body(): array
{
    $d = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($d)) fail(422, 'Invalid request: body must be a JSON object');
    return $d;
}

set_exception_handler(function (Throwable $e) {
    if ($e instanceof HttpError) json_out(['detail' => $e->getMessage()], $e->getCode());
    error_log('api error: ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    json_out(['detail' => 'Internal error'], 500);
});

// Schema + seeded owner, once per container.
if (!file_exists('/tmp/.af-init')) {
    db_init();
    touch('/tmp/.af-init');
}

// ---------------------------------------------------------------- auth
function sign(string $payload): string
{
    $mac = hash_hmac('sha256', $payload, getenv('SESSION_SECRET'));
    return rtrim(strtr(base64_encode("$payload|$mac"), '+/', '-_'), '=');
}

function current_user(): array
{
    $raw = base64_decode(strtr($_COOKIE[COOKIE] ?? '', '-_', '+/'), true) ?: '';
    $parts = explode('|', $raw);
    if (count($parts) !== 2) fail(401, 'Not signed in');
    [$payload, $mac] = $parts;
    if (!hash_equals(hash_hmac('sha256', $payload, getenv('SESSION_SECRET')), $mac)) fail(401, 'Not signed in');
    [$uid, $exp] = explode(':', $payload) + [1 => '0'];
    if ((int)$exp < time() || !is_uuid($uid)) fail(401, 'Not signed in');
    return q1('SELECT id, tenant_id, email, role FROM users WHERE id=?', $uid) ?? fail(401, 'Not signed in');
}

// ---------------------------------------------------------------- helpers
function public_agent(array $a, ?array $job = null): array
{
    $acct = $a['channel_account'] ?? '';
    if ($a['channel_type'] === 'whatsapp' && strlen($acct) > 6) {
        $acct = substr($acct, 0, 4) . str_repeat('•', strlen($acct) - 6) . substr($acct, -2);
    }
    $iso = fn(?string $t) => $t ? (new DateTimeImmutable($t))->format(DATE_ATOM) : null;
    return [
        'id' => $a['id'], 'name' => $a['name'], 'job' => $a['job'], 'channel_type' => $a['channel_type'],
        'channel_account' => $acct, 'enabled' => (bool)$a['enabled'], 'state' => $a['state'],
        'runtime' => $a['runtime'], 'model' => $a['model'], 'restricted' => json_decode($a['allowed_users'], true) !== [],
        'state_reason' => $a['state_reason'], 'last_message_at' => $iso($a['last_message_at']),
        'created_at' => $iso($a['created_at']), 'busy' => $job !== null,
        'pending_job' => $job ? ['type' => $job['type'], 'state' => $job['state'], 'step' => $job['current_step']] : null,
    ];
}

function active_job(string $agentId): ?array
{
    return q1("SELECT * FROM jobs WHERE agent_id=? AND state IN ('queued','running','rolling_back') LIMIT 1", $agentId);
}

function get_agent(string $id, array $u): array
{
    if (!is_uuid($id)) fail(404, 'Agent not found');
    return q1("SELECT * FROM agents WHERE id=? AND tenant_id=? AND state <> 'deleted'", $id, $u['tenant_id'])
        ?? fail(404, 'Agent not found');
}

function get_session(string $sid, array $u): array
{
    if (!is_uuid($sid)) fail(404, 'Session not found');
    $s = q1('SELECT * FROM agentfather_sessions WHERE id=? AND tenant_id=? AND user_id=?', $sid, $u['tenant_id'], $u['id'])
        ?? fail(404, 'Session not found');
    $s['messages'] = json_decode($s['messages'], true);
    $s['draft_config'] = $s['draft_config'] !== null ? json_decode($s['draft_config'], true) : null;
    return $s;
}

function session_out(array $s, ?string $error = null): array
{
    $state = $s['draft_config'] ?? ['answers' => [], 'asking' => af_next([])];
    $asking = array_key_exists('asking', $state) ? $state['asking'] : af_next($state['answers'] ?? []);
    $cfg = $err = null;
    if ($asking === null) [$cfg, $err] = validate_config(af_build_config($state['answers'] ?? []));
    return ['id' => $s['id'], 'messages' => $s['messages'], 'status' => $s['status'],
            'question' => af_question($asking), 'complete' => $asking === null && $cfg !== null,
            'config' => $cfg, 'validation_error' => $err, 'error' => $error];
}

// ---------------------------------------------------------------- routes
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = [];
$route = function (string $verb, string $pattern) use ($method, $path, &$m): bool {
    return $method === $verb && (bool)preg_match("#^$pattern$#", $path, $m);
};

if ($route('POST', '/api/login')) {
    $b = body();
    $u = q1('SELECT * FROM users WHERE email=?', strtolower(trim((string)($b['email'] ?? ''))));
    if (!$u || !password_verify((string)($b['password'] ?? ''), $u['password_hash'])) fail(401, 'Wrong email or password');
    setcookie(COOKIE, sign($u['id'] . ':' . (time() + 12 * 3600)),
              ['expires' => time() + 12 * 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    json_out(['email' => $u['email']]);
}

if ($route('POST', '/api/logout')) {
    setcookie(COOKIE, '', ['expires' => 1, 'path' => '/']);
    json_out(['ok' => true]);
}

$u = current_user();

if ($route('GET', '/api/me')) json_out(['email' => $u['email'], 'role' => $u['role']]);

if ($route('GET', '/api/agents')) {
    $rows = q("SELECT * FROM agents WHERE tenant_id=? AND state <> 'deleted' ORDER BY created_at DESC", $u['tenant_id']);
    json_out(array_map(fn($a) => public_agent($a, active_job($a['id'])), $rows));
}

if ($route('GET', '/api/agents/([0-9a-f-]+)')) {
    $a = get_agent($m[1], $u);
    $events = q('SELECT timestamp, type, state, result, duration_ms, job_id FROM events WHERE agent_id=? ORDER BY id DESC LIMIT 40', $a['id']);
    foreach ($events as &$e) {
        $e['timestamp'] = (new DateTimeImmutable($e['timestamp']))->format(DATE_ATOM);
        $e['duration_ms'] = $e['duration_ms'] !== null ? (int)$e['duration_ms'] : null;
    }
    json_out(public_agent($a, active_job($a['id'])) + ['events' => $events]);
}

if ($route('POST', '/api/agents/([0-9a-f-]+)/switch')) {
    $a = get_agent($m[1], $u);
    $b = body();
    if (!is_bool($b['enabled'] ?? null)) fail(422, 'Invalid request: enabled');
    $on = $b['enabled'];
    $type = $on ? ($a['state'] === 'failed' ? 'create' : 'enable') : 'disable';
    if (enqueue($a, $type) === null) fail(409, 'A change is already in progress for this agent');
    q('UPDATE agents SET enabled=?, state=? WHERE id=?', $on, $on ? 'starting' : 'stopping', $a['id']);
    event($a['tenant_id'], $a['id'], $on ? 'switch_on' : 'switch_off', 'requested');
    json_out(public_agent(q1('SELECT * FROM agents WHERE id=?', $a['id']), active_job($a['id'])));
}

if ($route('DELETE', '/api/agents/([0-9a-f-]+)')) {
    $a = get_agent($m[1], $u);
    if (enqueue($a, 'delete') === null) fail(409, 'A change is already in progress for this agent');
    event($a['tenant_id'], $a['id'], 'delete', 'requested');
    json_out(['ok' => true]);
}

if ($route('POST', '/api/agentfather/sessions')) {
    $sid = uuid4();
    $first = af_next([]);
    $msgs = [['role' => 'assistant', 'content' => AF_GREETING], ['role' => 'assistant', 'content' => af_prompt($first)]];
    q('INSERT INTO agentfather_sessions(id, tenant_id, user_id, messages, draft_config) VALUES (?,?,?,?::jsonb,?::jsonb)',
      $sid, $u['tenant_id'], $u['id'], json_encode($msgs), json_encode(['answers' => new stdClass(), 'asking' => $first]));
    json_out(session_out(get_session($sid, $u)));
}

if ($route('POST', '/api/agentfather/sessions/([0-9a-f-]+)/messages')) {
    $s = get_session($m[1], $u);
    if ($s['status'] !== 'open') fail(409, 'This conversation is closed');
    $b = body();
    $state = $s['draft_config'] ?? ['answers' => [], 'asking' => af_next([])];
    $state['answers'] = (array)($state['answers'] ?? []);
    if (($b['action'] ?? '') === 'change') {
        $new = ['answers' => $state['answers'], 'asking' => CHANGE_Q];
        $shown = 'I want to change something.';
    } else {
        $choices = $b['choices'] ?? [];
        $text = $b['text'] ?? '';
        if (!is_array($choices) || !is_string($text) || mb_strlen($text) > 4000) fail(422, 'Invalid request');
        $choices = array_values(array_filter($choices, 'is_string'));
        [$new, $err, $shown] = af_answer($state, $choices, $text);
        if ($err !== null) json_out(session_out($s, $err));
    }
    $msgs = [...$s['messages'], ['role' => 'user', 'content' => $shown], ['role' => 'assistant', 'content' => af_prompt($new['asking'])]];
    q('UPDATE agentfather_sessions SET messages=?::jsonb, draft_config=?::jsonb WHERE id=?',
      json_encode($msgs), json_encode(['answers' => (object)$new['answers'], 'asking' => $new['asking']]), $s['id']);
    json_out(session_out(get_session($s['id'], $u)));
}

if ($route('POST', '/api/agentfather/sessions/([0-9a-f-]+)/confirm')) {
    $s = get_session($m[1], $u);
    if ($s['status'] !== 'open') fail(409, 'This conversation is already closed');
    $state = $s['draft_config'] ?? [];
    if (!array_key_exists('asking', $state) || $state['asking'] !== null) fail(422, 'Answer all of AgentFather\'s questions first.');
    [$cfg, $err] = validate_config(af_build_config((array)($state['answers'] ?? [])));
    if (!$cfg) fail(422, 'Configuration rejected by the platform: ' . ($err ?? 'incomplete'));
    if ($cfg['channel_type'] === 'whatsapp') fail(422, 'WhatsApp is not available in this POC yet. Click Change and choose Telegram.');
    $b = body();
    if (array_diff(array_keys($b), ['telegram_token'])) fail(422, 'Invalid request: unknown fields');
    $token = trim((string)($b['telegram_token'] ?? ''));
    if ($token === '') fail(422, 'Paste the Telegram bot token from @BotFather.');
    // The token goes straight to the broker's secrets store; only a reference is kept here.
    try {
        $sec = broker('POST', '/secrets', ['kind' => 'telegram', 'value' => $token]);
    } catch (StepError $e) {
        $code = $e->getCode();
        fail($code >= 400 && $code < 500 ? $code : 502, $e->getMessage());
    }
    $aid = uuid4();
    q("INSERT INTO agents(id, tenant_id, name, job, instructions, prohibited_tasks, channel_type, channel_account,
         secret_ref, enabled, state, runtime, model, allowed_users) VALUES (?,?,?,?,?,?::jsonb,?,?,?,true,'starting',?,?,?::jsonb)",
      $aid, $u['tenant_id'], $cfg['name'], $cfg['job'], $cfg['instructions'], json_encode($cfg['prohibited_tasks']),
      $cfg['channel_type'], $sec['account'], $sec['secret_ref'], $cfg['runtime'], $cfg['model'], json_encode($cfg['allowed_users']));
    q("UPDATE agentfather_sessions SET status='confirmed' WHERE id=?", $s['id']);
    $a = q1('SELECT * FROM agents WHERE id=?', $aid);
    event($u['tenant_id'], $aid, 'config_validated', 'completed');
    enqueue($a, 'create');
    json_out(public_agent($a, active_job($aid)));
}

if ($route('POST', '/api/agentfather/sessions/([0-9a-f-]+)/cancel')) {
    $s = get_session($m[1], $u);
    q("UPDATE agentfather_sessions SET status='cancelled' WHERE id=? AND status='open'", $s['id']);
    json_out(['ok' => true]);
}

fail(404, 'Not found');
