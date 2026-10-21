<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The repository's queue configuration must be internally consistent, because the failure mode is silent: a worker on another
 * connection, or one that is not told about a queue, simply never runs the jobs (notifications, e-mails, certificate PDFs, Smart
 * Forms exports stay "queued" forever), and a `retry_after` shorter than a job's timeout hands a still-running job to a second worker.
 *
 *  1. every Supervisor worker in docs/supervisor.conf runs `queue:work <the connection .env.example dispatches to>`;
 *  2. together the workers consume EVERY queue the application dispatches to;
 *  3. `retry_after` of the redis and database connections is above every worker `--timeout` and every job `$timeout`.
 */
class QueueConfigurationTest extends TestCase
{
    private function supervisor(): string
    {
        return (string) file_get_contents(base_path('docs/supervisor.conf'));
    }

    /** @return list<array{connection: string, queues: list<string>, timeout: int}> */
    private function workers(): array
    {
        preg_match_all('/queue:work\s+(\w+)\s*\\\\\s*\n\s*--queue=([\w,]+)(.*?)(?=\n\s*\n|\nautostart)/s', $this->supervisor(), $m, PREG_SET_ORDER);
        $workers = [];
        foreach ($m as $row) {
            preg_match('/--timeout=(\d+)/', $row[3], $t);
            $workers[] = ['connection' => $row[1], 'queues' => explode(',', $row[2]), 'timeout' => (int) ($t[1] ?? 60)];
        }

        return $workers;
    }

    private function envExample(string $key): ?string
    {
        preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', (string) file_get_contents(base_path('.env.example')), $m);

        return isset($m[1]) ? trim($m[1]) : null;
    }

    /** @return list<string> */
    private function appFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_the_supervisor_example_defines_the_workers_the_documentation_describes(): void
    {
        $workers = $this->workers();

        $this->assertCount(2, $workers, 'docs/supervisor.conf must define the main worker and the webhook worker');
        $this->assertStringContainsString('emc-scheduler', $this->supervisor());
    }

    public function test_every_worker_runs_on_the_connection_the_application_dispatches_to(): void
    {
        $dispatchedTo = $this->envExample('QUEUE_CONNECTION');
        $this->assertNotNull($dispatchedTo);

        foreach ($this->workers() as $worker) {
            $this->assertSame($dispatchedTo, $worker['connection'], "a worker runs on '{$worker['connection']}' but .env.example dispatches to '{$dispatchedTo}': its jobs would never be processed");
        }
    }

    public function test_the_workers_together_consume_every_queue_the_application_dispatches_to(): void
    {
        $dispatched = ['default'];
        foreach ($this->appFiles('app') as $file) {
            $src = (string) file_get_contents($file);
            preg_match_all("/onQueue\(\s*(?:config\('[^']+',\s*'(\w+)'\)|'(\w+)')\s*\)/", $src, $m, PREG_SET_ORDER);
            foreach ($m as $row) {
                $dispatched[] = $row[1] !== '' ? $row[1] : $row[2];
            }
            preg_match_all('/public string \$queue\s*=\s*\'(\w+)\'/', $src, $q);
            array_push($dispatched, ...$q[1]);
        }
        $dispatched = array_values(array_unique($dispatched));

        $consumed = array_merge(...array_map(fn (array $w) => $w['queues'], $this->workers()));

        $this->assertEqualsCanonicalizing([], array_values(array_diff($dispatched, $consumed)), 'queues the application dispatches to that no worker consumes: '.implode(', ', array_diff($dispatched, $consumed)));
        $this->assertContains('emails', $dispatched, 'sanity: the scan must have found the named queues');
        $this->assertContains('certificates', $dispatched);
    }

    public function test_retry_after_is_above_every_worker_timeout_and_every_job_timeout(): void
    {
        $longestJob = 0;
        foreach ($this->appFiles('app/Jobs') as $file) {
            if (preg_match_all('/public int \$timeout\s*=\s*(\d+)/', (string) file_get_contents($file), $m)) {
                $longestJob = max($longestJob, ...array_map('intval', $m[1]));
            }
        }
        $longestWorker = max(array_map(fn (array $w) => $w['timeout'], $this->workers()));
        $this->assertGreaterThanOrEqual(300, $longestJob, 'sanity: the long AI / certificate / report jobs were found');

        foreach (['redis', 'database'] as $connection) {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

            $this->assertGreaterThan($longestJob, $retryAfter, "{$connection}: retry_after must exceed the longest job timeout ({$longestJob}s)");
            $this->assertGreaterThan($longestWorker, $retryAfter, "{$connection}: retry_after must exceed every worker --timeout ({$longestWorker}s)");
        }

        // and the values documented for operators in .env.example agree with the defaults the config falls back to
        $this->assertSame((int) config('queue.connections.redis.retry_after'), (int) $this->envExample('REDIS_QUEUE_RETRY_AFTER'));
        $this->assertSame((int) config('queue.connections.database.retry_after'), (int) $this->envExample('DB_QUEUE_RETRY_AFTER'));
    }
}
