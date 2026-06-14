<?php

namespace Vigilance\Support;

use Illuminate\Support\Str;

class Redactor
{
    public const PLACEHOLDER = '[redacted]';

    /**
     * Recursively redact array values whose key matches a configured secret
     * name (case-insensitive, substring match).
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        $patterns = (array) config('vigilance.redact', []);

        if ($patterns === []) {
            return $data;
        }

        $redacted = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && static::keyMatches($key, $patterns)) {
                $redacted[$key] = self::PLACEHOLDER;

                continue;
            }

            $redacted[$key] = is_array($value) ? static::redact($value) : $value;
        }

        return $redacted;
    }

    /** @param array<int, string> $patterns */
    protected static function keyMatches(string $key, array $patterns): bool
    {
        $key = Str::lower($key);

        foreach ($patterns as $pattern) {
            if (str_contains($key, Str::lower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }
}
