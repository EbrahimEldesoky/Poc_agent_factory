<?php
declare(strict_types=1);
/* Postgres-backed job queue + reconciler. Hands create/enable/disable/delete jobs to the broker. */

require_once __DIR__ . '/db.php';

final class StepError extends RuntimeException {}

/** Calls the Deployment Broker. Errors become StepError with a safe message (never a secret). */
function broker(string $method, string $path, ?array $json = null, array $query = []): array
{
    $url = rtrim(getenv('BROKER_URL'), '/') . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    $headers = ['X-Broker-Token: ' . getenv('BROKER_TOKEN')];
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150, CURLOPT_CONNECTTIMEOUT => 5];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($json);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code === 0) throw new StepError('Deployment broker unreachable');
    $data = json_decode($body, true);
    if ($code >= 400) {
        $detail = is_array($data) && is_string($data['detail'] ?? null) ? $data['detail'] : "broker error HTTP $code";
        throw new StepError($detail, $code);
    }
    return is_array($data) ? $data : [];
}

/** @return string|null job id, or null if a job is already in flight for this agent */
function enqueue(array $agent, string $type): ?string
{
    $id = uuid4();
    try {
        q("INSERT INTO jobs(id, tenant_id, agent_id, type, state, idempotency_key) VALUES (?,?,?,?,'queued',?)",
          $id, $agent['tenant_id'], $agent['id'], $type, "{$agent['id']}:$type:$id");
    } catch (PDOException) { // unique index one_active_job_per_agent
        return null;
    }
    event($agent['tenant_id'], $agent['id'], "job_{$type}_queued", 'queued', null, $id);
    return $id;
}

function manifest(array $a): array
{
    return [
        'agent_id' => $a['id'], 'name' => $a['name'], 'job' => $a['job'], 'instructions' => $a['instructions'],
        'prohibited_tasks' => json_decode($a['prohibited_tasks'], true), 'channel_type' => $a['channel_type'],
        'secret_ref' => $a['secret_ref'], 'runtime' => $a['runtime'], 'model' => $a['model'],
        'allowed_users' => json_decode($a['allowed_users'], true),
    ];
}

function set_agent(string $id, array $fields): void
{
    $cols = implode(', ', array_map(fn($k) => "$k=?", array_keys($fields)));
    q("UPDATE agents SET $cols WHERE id=?", ...[...array_values($fields), $id]);
}

function run_job(array $job): void
{
    $a = q1('SELECT * FROM agents WHERE id=?', $job['agent_id']);
    $tenant = $a['tenant_id'];
    $jid = $job['id'];
    $aid = $a['id'];

    $step = function (string $name, callable $fn) use ($tenant, $aid, $jid) {
        q('UPDATE jobs SET current_step=?, updated_at=now() WHERE id=?', $name, $jid);
        $t0 = microtime(true);
        try {
            $res = $fn();
        } catch (StepError $e) {
            event($tenant, $aid, $name, 'failed', $e->getMessage(), $jid, (int)((microtime(true) - $t0) * 1000));
            throw $e;
        }
        event($tenant, $aid, $name, 'completed', null, $jid, (int)((microtime(true) - $t0) * 1000));
        return $res;
    };

    try {
        switch ($job['type']) {
            case 'create':
            case 'enable':
                set_agent($aid, ['state' => 'starting', 'state_reason' => null]);
                $step('validate_config', function () use ($a) {
                    if ($a['channel_type'] !== 'telegram') {
                        throw new StepError('WhatsApp is not available in this POC (OpenClaw only supports WhatsApp Web linking). See README.');
                    }
                });
                // On always rebuilds the container from the manifest, so it gets the current secrets.
                $res = $step('create_container', fn() => broker('POST', "/agents/$aid/create", manifest($a)));
                set_agent($aid, ['container_id' => $res['container_id']]);
                $step('start_and_health_check', fn() => broker('POST', "/agents/$aid/start"));
                set_agent($aid, ['state' => 'active', 'state_reason' => null]);
                break;
            case 'disable':
                set_agent($aid, ['state' => 'stopping']);
                $step('stop_container', fn() => broker('POST', "/agents/$aid/stop"));
                set_agent($aid, ['state' => 'inactive']);
                break;
            case 'delete':
                $step('delete_container_and_channel', fn() => broker('DELETE', "/agents/$aid", null, ['secret_ref' => $a['secret_ref'] ?? '']));
                set_agent($aid, ['state' => 'deleted', 'enabled' => false, 'container_id' => null]);
                break;
        }
        q("UPDATE jobs SET state='completed', current_step=NULL, updated_at=now() WHERE id=?", $jid);
    } catch (Throwable $e) {
        $reason = $e instanceof StepError ? $e->getMessage() : 'Unexpected error in the platform';
        if (!$e instanceof StepError) error_log("job $jid crashed: " . get_class($e));
        $failedStep = q1('SELECT current_step FROM jobs WHERE id=?', $jid)['current_step'] ?? null;
        if (in_array($job['type'], ['create', 'enable'], true)) {
            // Safe failure: remove what was created (keep the channel secret so a retry works).
            q("UPDATE jobs SET state='rolling_back', updated_at=now() WHERE id=?", $jid);
            try {
                broker('DELETE', "/agents/$aid", null, ['secret_ref' => $a['secret_ref'] ?? '', 'keep_secret' => 'true']);
                event($tenant, $aid, 'rollback', 'completed', null, $jid);
            } catch (StepError $e2) {
                event($tenant, $aid, 'rollback', 'failed', $e2->getMessage() . ' (cleanup will run on retry)', $jid);
            }
            set_agent($aid, ['state' => 'failed', 'enabled' => false, 'container_id' => null,
                             'state_reason' => "$reason (step: $failedStep)"]);
        } elseif ($job['type'] === 'delete') {
            set_agent($aid, ['state' => 'failed', 'state_reason' => "Delete failed: $reason"]);
        } else {
            set_agent($aid, ['state' => 'failed', 'state_reason' => $reason]);
        }
        q("UPDATE jobs SET state='failed', error=?, updated_at=now() WHERE id=?", $reason, $jid);
    }
}

function claim_job(): ?array
{
    return q1("UPDATE jobs SET state='running', updated_at=now() WHERE id = (
                 SELECT id FROM jobs WHERE state='queued' ORDER BY created_at
                 FOR UPDATE SKIP LOCKED LIMIT 1) RETURNING *");
}

/** Make the list reflect where containers really are, and record message activity. */
function reconcile(): void
{
    $rows = q("SELECT * FROM agents WHERE state IN ('active','inactive') AND NOT EXISTS (
                 SELECT 1 FROM jobs j WHERE j.agent_id=agents.id AND j.state IN ('queued','running','rolling_back'))");
    foreach ($rows as $a) {
        try {
            $s = broker('GET', "/agents/{$a['id']}/status");
        } catch (StepError) {
            continue;
        }
        if ($a['state'] === 'active' && !$s['running']) {
            set_agent($a['id'], ['state' => 'failed', 'enabled' => false, 'state_reason' => 'Container stopped unexpectedly']);
            event($a['tenant_id'], $a['id'], 'container_lost', 'failed');
        }
        if (!empty($s['last_activity'])) {
            $ts = (float)$s['last_activity'];
            $prev = $a['last_message_at'] ? (float)(new DateTimeImmutable($a['last_message_at']))->format('U.u') : 0.0;
            if ($ts > $prev + 0.001) {
                q('UPDATE agents SET last_message_at=to_timestamp(?) WHERE id=?', $ts, $a['id']);
                event($a['tenant_id'], $a['id'], 'message_handled', 'completed', null, null, null, $a['channel_type']);
            }
        }
    }
}
