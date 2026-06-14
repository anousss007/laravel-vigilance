<?php

namespace Vigilance\Data;

use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;

/**
 * A thin, array-backed carrier for run attributes. Only the keys you set are
 * included in toArray(), which lets the repository perform partial updates
 * (set only the fields that changed for a given lifecycle transition).
 */
class RunData
{
    /** @param array<string, mixed> $attributes */
    public function __construct(public array $attributes = []) {}

    /** @param array<string, mixed> $attributes */
    public static function make(array $attributes = []): self
    {
        return new self($attributes);
    }

    public function set(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function type(RunType $type): self
    {
        return $this->set('type', $type->value);
    }

    public function status(RunStatus $status): self
    {
        return $this->set('status', $status->value);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
