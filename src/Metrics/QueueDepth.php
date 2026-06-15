<?php

namespace Vigilance\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Best-effort, driver-aware live backlog for a queue.
 *
 * "Depth" means jobs waiting to be processed right now. It is answered for
 * every supervisable driver: "database" (COUNT of unreserved rows), "redis"
 * (LLEN), "beanstalkd" (stats-tube current-jobs-ready) and "sqs"
 * (ApproximateNumberOfMessages). For drivers with no backlog to speak of
 * (sync, null) or any unknown driver we return null ("unknown") rather than a
 * misleading zero — the auto-scaler then idles the pool at min_processes. This
 * never throws.
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
                'beanstalkd' => $this->beanstalkdDepth($connection, $queue),
                'sqs' => $this->sqsDepth($connection, $queue),
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
        $db = DB::connection($dbConnection);

        // Probe for the table first: a missing table would throw, and on
        // Postgres a thrown query inside a transaction aborts the whole
        // transaction — so "best effort" must mean "never run a failing query".
        if (! $db->getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        return (int) $db->table($table)
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

    /**
     * Ready jobs on a beanstalkd tube via "stats-tube" (current-jobs-ready).
     * Handles both pheanstalk v4 (ArrayAccess) and v5 (typed TubeStats / TubeName).
     */
    protected function beanstalkdDepth(string $connection, string $queue): ?int
    {
        try {
            $q = app('queue')->connection($connection);

            if (! method_exists($q, 'getPheanstalk')) {
                return null;
            }

            // pheanstalk v5 takes a TubeName value object; v4 takes a string.
            // Referenced as a string so the package needs no hard dependency on
            // (or symbol from) pheanstalk when another driver is in use.
            $tubeNameClass = 'Pheanstalk\\Values\\TubeName';
            $tube = class_exists($tubeNameClass) ? new $tubeNameClass($queue) : $queue;

            $stats = $q->getPheanstalk()->statsTube($tube);

            if (is_object($stats) && isset($stats->currentJobsReady)) {
                return (int) $stats->currentJobsReady; // pheanstalk v5
            }

            if (($stats instanceof \ArrayAccess || is_array($stats)) && isset($stats['current-jobs-ready'])) {
                return (int) $stats['current-jobs-ready']; // pheanstalk v4
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Approximate visible messages on an SQS queue via GetQueueAttributes. SQS
     * only ever reports an approximation, which is exactly what the auto-scaler
     * needs (relative load), and it is one cheap API call per evaluation.
     */
    protected function sqsDepth(string $connection, string $queue): ?int
    {
        try {
            $q = app('queue')->connection($connection);

            if (! method_exists($q, 'getSqs') || ! method_exists($q, 'getQueue')) {
                return null;
            }

            $result = $q->getSqs()->getQueueAttributes([
                'QueueUrl' => $q->getQueue($queue),
                'AttributeNames' => ['ApproximateNumberOfMessages'],
            ]);

            $count = $result['Attributes']['ApproximateNumberOfMessages'] ?? null;

            return $count !== null ? (int) $count : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
