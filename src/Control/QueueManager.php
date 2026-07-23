<?php

namespace Vigilance\Control;

use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Supervision\ControlPlane;

/**
 * The queue-level control surface behind the dashboard: pause / resume a single
 * queue, purge a queue's backlog, and cancel individual pending jobs. Pausing is
 * an operational lever (safe, like the global pause) and is always available;
 * the destructive purge/cancel operations require the manual-control master
 * switch (vigilance.control.enabled) and are always audited.
 */
class QueueManager
{
    public function __construct(
        protected ControlPlane $control = new ControlPlane,
        protected AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * Pause a single queue. $seconds gives a timed pause that auto-resumes; null
     * pauses indefinitely. The supervisor stops pulling from this queue on its
     * next tick while every other queue keeps running.
     */
    public function pause(string $connection, string $queue, ?int $seconds = null, ?string $user = null): void
    {
        $this->control->pauseQueue($connection, $queue, $seconds);

        $this->audit->log(
            action: 'pause_queue',
            subject: $connection.':'.$queue,
            meta: ['connection' => $connection, 'queue' => $queue, 'seconds' => $seconds],
            user: $user,
        );
    }

    public function resume(string $connection, string $queue, ?string $user = null): void
    {
        $this->control->continueQueue($connection, $queue);

        $this->audit->log(
            action: 'resume_queue',
            subject: $connection.':'.$queue,
            meta: ['connection' => $connection, 'queue' => $queue],
            user: $user,
        );
    }

    /**
     * Delete every job waiting on a queue. Works for any connection whose queue
     * implements Laravel's ClearableQueue (database, redis, sqs). Beanstalkd and
     * sync/null do not, and are rejected with NotAllowed. Returns the number of
     * jobs removed (0 when the driver does not report a count).
     *
     * @throws NotAllowed when manual control is disabled or the driver can't be cleared
     */
    public function clear(string $connection, string $queue, ?string $user = null): int
    {
        $this->ensureControlEnabled();

        $instance = Queue::connection($connection);

        if (! $instance instanceof ClearableQueue) {
            throw new NotAllowed("The [{$connection}] queue driver does not support clearing.");
        }

        $count = (int) $instance->clear($queue);

        $this->audit->log(
            action: 'clear_queue',
            subject: $connection.':'.$queue,
            meta: ['connection' => $connection, 'queue' => $queue, 'deleted' => $count],
            user: $user,
        );

        return $count;
    }

    /**
     * Cancel (delete) specific pending jobs by their backend id. Supported for
     * the database driver only — other drivers have no addressable per-job
     * handle to delete (use clear() to purge the whole queue there).
     *
     * @param  list<int>  $ids
     *
     * @throws NotAllowed when manual control is disabled or the driver is not "database"
     */
    public function deletePending(string $connection, array $ids, ?string $user = null): int
    {
        $this->ensureControlEnabled();

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            throw new NotAllowed("Cancelling individual pending jobs is only supported for the database driver, not [{$connection}].");
        }

        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0));

        if ($ids === []) {
            return 0;
        }

        $deleted = (int) DB::connection(config("queue.connections.{$connection}.connection"))
            ->table((string) config("queue.connections.{$connection}.table", 'jobs'))
            ->whereIn('id', $ids)
            ->delete();

        $this->audit->log(
            action: 'cancel_pending',
            subject: $connection,
            meta: ['connection' => $connection, 'ids' => $ids, 'deleted' => $deleted],
            user: $user,
        );

        return $deleted;
    }

    protected function ensureControlEnabled(): void
    {
        if (! config('vigilance.control.enabled', false)) {
            throw new NotAllowed('Manual control is disabled. Set VIGILANCE_CONTROL_ENABLED=true to purge queues or cancel jobs from the dashboard.');
        }
    }
}
