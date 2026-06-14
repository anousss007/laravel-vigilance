<?php

namespace Vigilance\Apm;

/**
 * A latest-wins snapshot value (upserted by type+key), e.g. a server's current
 * CPU/memory reading.
 */
class Value
{
    public function __construct(
        public int $timestamp,
        public string $type,
        public string $key,
        public string $value,
    ) {}

    /** @return array{timestamp:int, type:string, key:string, value:string} */
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
