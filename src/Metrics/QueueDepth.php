<?php

namespace Vigilance\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Best-effort, driver-aware live backlog for a queue.
 *
 * "Depth" means jobs waiting to be processed right now, which only the
 * "database" and "redis" queue drivers can answer cheaply and accurately.
 * For every other driver (sqs, beanstalkd, sync, null, ...) the backlog is
 * either unknowable from here or meaningless, so we return null ("unknown")
 * rather than a misleading zero. This never throws.
 */
class QueueDepth
{
    /**
     * Number of jobs pending on the given queue, or null when the driver
     * cannot answer.
     */
    public function for(string $connection, string $queue): ?int
    {
        try {
            $driver = config("queue.connections.$connection.driver");

            return match ($driver) {
                'database' => $this->databaseDepth($connection, $queue),
                'redis' => $this->redisDepth($connection, $queue),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Alias for {@see self::for()} reading as a verb.
     */
    public function pending(string $connection, string $queue): ?int
    {
        return $this->for($connection, $queue);
    }

    /**
     * Count unreserved rows in the queue connection's jobs table, on whichever
     * DB connection that queue is configured to use.
     */
    protected function databaseDepth(string $connection, string $queue): ?int
    {
        $table = config("queue.connections.$connection.table", 'jobs');
        $dbConnection = config("queue.connections.$connection.connection");

        return (int) DB::connection($dbConnection)
            ->table($table)
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->count();
    }

    /**
     * Length of the Redis list backing the queue. A single LLEN, no SCAN, so
     * it stays O(1) and safe to call from a dashboard request.
     */
    protected function redisDepth(string $connection, string $queue): ?int
    {
        $redisConnection = config("queue.connections.$connection.connection", 'default');

        try {
            return (int) Redis::connection($redisConnection)->llen("queues:$queue");
        } catch (\Throwable) {
            return null;
        }
    }
}
