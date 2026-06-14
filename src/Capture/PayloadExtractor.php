<?php

namespace Vigilance\Capture;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Vigilance\Support\Redactor;

/**
 * Turns a queued job payload into a safe, displayable representation of the
 * job's constructor parameters. Eloquent models are reduced to {class, id}
 * (never the full row) and secret-looking keys are redacted.
 */
class PayloadExtractor
{
    /**
     * Resolve the command object from a queue payload. Returns null if the
     * payload is encrypted or cannot be unserialized.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function command(array $payload): ?object
    {
        $serialized = $payload['data']['command'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        try {
            // Payloads are produced by our own application's dispatch calls,
            // so they are trusted; allowing all classes lets us reflect the
            // real properties (models, value objects, etc.).
            $command = @unserialize($serialized, ['allowed_classes' => true]);
        } catch (\Throwable) {
            return null;
        }

        return is_object($command) ? $command : null;
    }

    /**
     * Bookkeeping properties contributed by Laravel's Queueable / Batchable /
     * InteractsWithQueue traits. They are framework plumbing, not the
     * developer's data, so they are hidden from the captured parameters.
     *
     * @var list<string>
     */
    protected const FRAMEWORK_PROPERTIES = [
        'job', 'connection', 'queue', 'delay', 'afterCommit', 'middleware',
        'chained', 'chainConnection', 'chainQueue', 'chainCatchCallbacks',
        'messageGroup', 'deduplicator', 'debounceOwner', 'deleteWhenMissingModels',
        'batchId', 'fakeBatch', 'shouldBeEncrypted',
    ];

    /**
     * Extract and redact the developer-facing properties of a job (its
     * constructor data), skipping framework trait plumbing.
     *
     * @return array<string, mixed>
     */
    public static function parameters(object $command): array
    {
        $values = [];

        try {
            $properties = (new \ReflectionClass($command))->getProperties();
        } catch (\Throwable) {
            return [];
        }

        foreach ($properties as $property) {
            if ($property->isStatic() || ! $property->isInitialized($command)) {
                continue;
            }

            if (in_array($property->getName(), self::FRAMEWORK_PROPERTIES, true)) {
                continue;
            }

            $values[$property->getName()] = static::normalize($property->getValue($command));
        }

        $values = Redactor::redact($values);

        return static::truncate($values);
    }

    protected static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return '…';
        }

        if ($value instanceof EloquentCollection) {
            $first = $value->first();

            return [
                '@collection' => $first !== null ? get_class($first) : null,
                'ids' => $value->map(fn (Model $m) => $m->getKey())->all(),
            ];
        }

        if ($value instanceof Model) {
            return ['@model' => get_class($value), 'id' => $value->getKey()];
        }

        if ($value instanceof \BackedEnum) {
            return ['@enum' => get_class($value), 'value' => $value->value];
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            return array_map(fn ($v) => static::normalize($v, $depth + 1), $value);
        }

        if (is_object($value)) {
            return ['@object' => get_class($value)];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected static function truncate(array $values): array
    {
        $max = (int) config('vigilance.capture.max_parameter_length', 16384);
        $encoded = json_encode($values);

        if ($encoded !== false && strlen($encoded) <= $max) {
            return $values;
        }

        return ['@truncated' => true, 'preview' => mb_substr((string) $encoded, 0, $max)];
    }
}
