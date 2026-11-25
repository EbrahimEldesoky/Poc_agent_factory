<?php
declare(strict_types=1);
/* Job worker: a long-running CLI process (compose service "worker", same image as the API). */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jobs.php';

assert_api_config(false);
db_init();
$recovered = recover_interrupted_jobs(true);
if ($recovered > 0) {
    fwrite(STDERR, "worker recovered $recovered interrupted job(s)\n");
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
            recover_interrupted_jobs(false);
            reconcile();
            $lastReconcile = microtime(true);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'worker loop error: ' . get_class($e) . "\n");
    }
    usleep(500_000);
}
