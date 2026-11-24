<?php
declare(strict_types=1);
/* Job worker: a long-running CLI process (compose service "worker", same image as the API). */
require_once __DIR__ . '/jobs.php';

db_init();
// Jobs left 'running' by a crashed worker are failed cleanly; the owner retries with the switch.
foreach (q("SELECT * FROM jobs WHERE state IN ('running','rolling_back')") as $j) {
    q("UPDATE jobs SET state='failed', error='Interrupted by a platform restart' WHERE id=?", $j['id']);
    set_agent($j['agent_id'], ['state' => 'failed', 'enabled' => false, 'state_reason' => 'Interrupted by a platform restart']);
}
fwrite(STDERR, "worker started\n");
$lastReconcile = 0.0;
while (true) {
    try {
        if ($job = claim_job()) {
            fwrite(STDERR, "job {$job['id']} {$job['type']} started\n");
            run_job($job);
            continue;
        }
        if (microtime(true) - $lastReconcile > 5) {
            reconcile();
            $lastReconcile = microtime(true);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'worker loop error: ' . get_class($e) . "\n");
    }
    usleep(500_000);
}
