<?php

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravents\Async\ExecuteHandlerJob;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * Runs the call and returns the exception it throws, so a test can assert on its details.
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $class
 * @return T
 */
function caught(string $class, Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }

        throw $e;
    }

    throw new RuntimeException(sprintf('Expected %s to be thrown, but nothing was.', $class));
}

/**
 * Counts the attempts the worker starts from here on, retries included; call the returned closure to read it.
 *
 * @return Closure(): int
 */
function countAttempts(): Closure
{
    $attempts = 0;
    Event::listen(JobProcessing::class, static function () use (&$attempts): void {
        $attempts++;
    });

    return static function () use (&$attempts): int {
        return $attempts;
    };
}

/**
 * Runs `php artisan queue:work` in this process until the queue is empty, and returns its output.
 * A real worker is its own process where nobody is logged in, so the test's user is forgotten first:
 * whatever user a job runs as, the job put there.
 * --sleep=0: otherwise the worker waits three seconds before it notices the queue is empty.
 * --memory: a test process can be past the default 128 MB, which would stop the worker after one job.
 */
function runWorker(string $queue): string
{
    auth()->forgetGuards();

    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => $queue,
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--memory' => 1024,
    ]);

    return Artisan::output();
}

/**
 * The jobs waiting in the jobs table, unserialized, oldest first.
 *
 * @return list<ExecuteHandlerJob>
 */
function queuedJobs(): array
{
    $jobs = [];

    foreach (DB::table('jobs')->orderBy('id')->pluck('payload') as $payload) {
        $decoded = is_string($payload) ? json_decode($payload, true) : null;
        $data = is_array($decoded) ? $decoded['data'] ?? null : null;
        $command = is_array($data) ? $data['command'] ?? null : null;
        $job = is_string($command) ? unserialize($command) : null;

        if (! $job instanceof ExecuteHandlerJob) {
            throw new RuntimeException('The jobs table holds something other than an ExecuteHandlerJob.');
        }

        $jobs[] = $job;
    }

    return $jobs;
}

/** The outbox row a job carries the id of. */
function publicationOf(ExecuteHandlerJob $job): EventPublication
{
    return EventPublication::query()->findOrFail($job->publicationId);
}
