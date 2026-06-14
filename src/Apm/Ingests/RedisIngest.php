<?php

namespace Vigilance\Apm\Ingests;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Entry;
use Vigilance\Apm\Value;

/**
 * Write-behind ingest: pushes buffered entries onto a Redis stream during the
 * request (a cheap XADD, no database write on the hot path), to be digested
 * into storage out-of-band by `vigilance:apm-work`. Use this when DB write
 * volume during the terminate phase becomes the bottleneck at very high traffic.
 */
class RedisIngest implements Ingest
{
    public function __construct(protected Storage $storage) {}

    public function ingest(Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $connection = $this->connection();
        $stream = $this->stream();
        $maxLength = $this->maxLength();

        // XADD with an (approximate) MAXLEN keeps the stream bounded even if the
        // digest worker falls behind. Routed through command() so it works on
        // both phpredis and predis.
        foreach ($items as $item) {
            $connection->command('xadd', [$stream, '*', ['data' => static::encode($item)], $maxLength, true]);
        }
    }

    public function digest(Storage $storage): int
    {
        $connection = $this->connection();
        $stream = $this->stream();
        $chunk = $this->chunkSize();
        $total = 0;

        while (true) {
            $entries = (array) $connection->command('xrange', [$stream, '-', '+', $chunk]);

            if ($entries === []) {
                break;
            }

            $items = new Collection;
            $ids = [];

            foreach ($entries as $id => $fields) {
                $ids[] = (string) $id;
                $decoded = static::decode((string) (is_array($fields) ? ($fields['data'] ?? '') : ''));

                if ($decoded !== null) {
                    $items->push($decoded);
                }
            }

            $storage->store($items);
            $connection->command('xdel', [$stream, $ids]);
            $total += count($ids);

            if (count($entries) < $chunk) {
                break;
            }
        }

        return $total;
    }

    public function trim(): void
    {
        // Storage trimming is performed by the digest worker (vigilance:apm-work),
        // not on the request's hot path.
    }

    public static function encode(Entry|Value $item): string
    {
        return base64_encode(serialize($item));
    }

    public static function decode(string $data): Entry|Value|null
    {
        if ($data === '') {
            return null;
        }

        $object = @unserialize(base64_decode($data), ['allowed_classes' => [Entry::class, Value::class]]);

        return $object instanceof Entry || $object instanceof Value ? $object : null;
    }

    /**
     * @return Connection
     */
    protected function connection()
    {
        return Redis::connection(config('vigilance.apm.ingest.connection'));
    }

    protected function stream(): string
    {
        return (string) config('vigilance.apm.ingest.stream', 'vigilance:apm:stream');
    }

    protected function maxLength(): int
    {
        return (int) config('vigilance.apm.ingest.stream_max_length', 10000);
    }

    protected function chunkSize(): int
    {
        return (int) (config('vigilance.apm.storage.chunk') ?? 1000);
    }
}
