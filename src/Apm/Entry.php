<?php

namespace Vigilance\Apm;

/**
 * A single recordable APM metric (one event). The requested aggregations are
 * metadata consumed by storage (which rolls them into time buckets) — they are
 * not persisted on the raw entry row.
 */
class Entry
{
    /** @var list<string> */
    public array $aggregations = [];

    protected bool $onlyBuckets = false;

    public function __construct(
        public int $timestamp,
        public string $type,
        public string $key,
        public ?int $value = null,
    ) {}

    public function count(): static
    {
        $this->aggregations[] = 'count';

        return $this;
    }

    public function min(): static
    {
        $this->aggregations[] = 'min';

        return $this;
    }

    public function max(): static
    {
        $this->aggregations[] = 'max';

        return $this;
    }

    public function sum(): static
    {
        $this->aggregations[] = 'sum';

        return $this;
    }

    public function avg(): static
    {
        $this->aggregations[] = 'avg';

        return $this;
    }

    /**
     * Skip the raw entries table and only write rolled buckets (for high-volume,
     * low-cardinality metrics where the raw tail is never needed).
     */
    public function onlyBuckets(): static
    {
        $this->onlyBuckets = true;

        return $this;
    }

    public function isOnlyBuckets(): bool
    {
        return $this->onlyBuckets;
    }

    /** @return array{timestamp:int, type:string, key:string, value:?int} */
    public function attributes(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'type' => $this->type,
            'key' => $this->key,
            'value' => $this->value,
        ];
    }
}
