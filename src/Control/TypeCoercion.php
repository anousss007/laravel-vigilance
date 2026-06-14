<?php

namespace Vigilance\Control;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vigilance\Control\Exceptions\InvalidParameter;

/**
 * Coerces a submitted form value (typically a string or array of strings) into
 * the PHP type a job constructor parameter expects, using the descriptor
 * produced by JobReflector. Handles scalars, backed enums, Carbon dates and
 * Eloquent model resolution.
 */
class TypeCoercion
{
    /** @param array<string, mixed> $descriptor */
    public function coerce(mixed $value, array $descriptor): mixed
    {
        if ($this->isEmpty($value)) {
            if (! empty($descriptor['has_default'])) {
                return $descriptor['default'] ?? null;
            }

            if (! empty($descriptor['nullable'])) {
                return null;
            }

            throw new InvalidParameter(
                "Missing required parameter [{$descriptor['name']}].",
            );
        }

        if (! empty($descriptor['is_model'])) {
            return $this->coerceModel($value, $descriptor);
        }

        if (! empty($descriptor['is_enum'])) {
            return $this->coerceEnum($value, $descriptor);
        }

        $builtin = $descriptor['builtin'] ?? null;

        // No declared scalar type — pass the value through untouched.
        if ($builtin === null) {
            if ($this->isDateType($descriptor)) {
                return $this->coerceDate($value);
            }

            return $value;
        }

        return match ($builtin) {
            'int' => $this->toInt($value, $descriptor),
            'float' => $this->toFloat($value, $descriptor),
            'bool' => $this->toBool($value),
            'array' => $this->toArray($value),
            'string' => $this->toString($value, $descriptor),
            default => $value,
        };
    }

    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @param array<string, mixed> $descriptor */
    protected function coerceModel(mixed $value, array $descriptor): object
    {
        /** @var class-string<Model> $class */
        $class = $descriptor['model_class'];

        try {
            return $class::query()->findOrFail($value);
        } catch (\Throwable $e) {
            throw new InvalidParameter(
                "Could not resolve {$class} for parameter [{$descriptor['name']}] (id: ".
                (is_scalar($value) ? (string) $value : gettype($value)).').',
                0,
                $e,
            );
        }
    }

    /** @param array<string, mixed> $descriptor */
    protected function coerceEnum(mixed $value, array $descriptor): \BackedEnum
    {
        /** @var class-string<\BackedEnum> $class */
        $class = $descriptor['enum_class'];

        try {
            // Match the enum's backing type (int-backed enums need an int).
            $cases = $class::cases();
            $backing = $cases !== [] && is_int($cases[0]->value) ? (int) $value : (string) $value;

            return $class::from($backing);
        } catch (\Throwable $e) {
            throw new InvalidParameter(
                "Invalid value for enum parameter [{$descriptor['name']}].",
                0,
                $e,
            );
        }
    }

    protected function coerceDate(mixed $value): Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            throw new InvalidParameter('Invalid date value.', 0, $e);
        }
    }

    /** @param array<string, mixed> $descriptor */
    protected function isDateType(array $descriptor): bool
    {
        $type = $descriptor['type'] ?? null;

        if (! is_string($type)) {
            return false;
        }

        return is_a($type, \DateTimeInterface::class, true)
            || is_a($type, CarbonInterface::class, true);
    }

    /** @param array<string, mixed> $descriptor */
    protected function toInt(mixed $value, array $descriptor): int
    {
        if (! is_numeric($value)) {
            throw new InvalidParameter("Parameter [{$descriptor['name']}] must be an integer.");
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $descriptor */
    protected function toFloat(mixed $value, array $descriptor): float
    {
        if (! is_numeric($value)) {
            throw new InvalidParameter("Parameter [{$descriptor['name']}] must be a number.");
        }

        return (float) $value;
    }

    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }

    /** @return array<mixed> */
    protected function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            // Fall back to a comma-separated list.
            return array_map('trim', explode(',', $value));
        }

        return [$value];
    }

    /** @param array<string, mixed> $descriptor */
    protected function toString(mixed $value, array $descriptor): string
    {
        if (is_array($value)) {
            throw new InvalidParameter("Parameter [{$descriptor['name']}] must be a string.");
        }

        return (string) $value;
    }
}
