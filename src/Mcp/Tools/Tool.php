<?php

namespace Vigilance\Mcp\Tools;

use Carbon\CarbonInterval;
use DateTimeInterface;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool as BaseTool;
use Vigilance\Support\Redactor;
use Vigilance\Vigilance;

/**
 * Shared base for every Vigilance MCP tool. Centralises the safety posture that
 * keeps the server production-grade: secret redaction, field truncation, row
 * caps, and a single JSON response shape — so no individual tool can leak a
 * secret or dump the whole database into the agent's context window.
 */
abstract class Tool extends BaseTool
{
    /**
     * Clean, stable tool names the agent calls by (and that the server's
     * instructions reference): "OverviewTool" → "overview", "SlowQueriesTool" →
     * "slow-queries", "ResolveIssueTool" → "resolve-issue". An explicit #[Name]
     * attribute still wins.
     */
    public function name(): string
    {
        $attribute = $this->resolveAttribute(Name::class);

        if ($attribute !== null) {
            return $attribute->value;
        }

        $base = class_basename($this);

        return Str::kebab((string) preg_replace('/Tool$/', '', $base) ?: $base);
    }

    /**
     * Clamp a caller-requested row limit into [1, config max_results]. A null /
     * non-positive request falls back to the configured maximum.
     */
    protected function resolveLimit(?int $requested = null): int
    {
        $max = max(1, (int) config('vigilance.mcp.max_results', 50));

        if ($requested === null || $requested <= 0) {
            return $max;
        }

        return min($requested, $max);
    }

    protected function maxFieldLength(): int
    {
        return max(0, (int) config('vigilance.mcp.max_field_length', 4000));
    }

    /**
     * Truncate a long string field, flagging that it was cut so the agent knows
     * the value is bounded rather than genuinely short.
     */
    protected function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $max = $this->maxFieldLength();
        $length = mb_strlen($value);

        if ($max > 0 && $length > $max) {
            return mb_substr($value, 0, $max)."… [truncated {$length} → {$max} chars]";
        }

        return $value;
    }

    /**
     * Redact secret-looking keys from an array payload before it leaves the
     * process. Mirrors the dashboard's storage-side redaction exactly.
     *
     * @param  array<mixed>|null  $data
     * @return array<mixed>|null
     */
    protected function redact(?array $data): ?array
    {
        if ($data === null || $data === []) {
            return $data;
        }

        return Redactor::redact($data);
    }

    /** Format any date-like value as ISO-8601, or null. */
    protected function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('c') : null;
    }

    /**
     * Turn a simple window string ('15m', '1h', '24h', '7d', '2w') into a
     * CarbonInterval for the APM read methods. Falls back to one hour.
     */
    protected function interval(string $window): CarbonInterval
    {
        if (! preg_match('/^(\d+)\s*(m|h|d|w)$/i', trim($window), $m)) {
            return CarbonInterval::hour();
        }

        $value = max(1, (int) $m[1]);

        return match (strtolower($m[2])) {
            'm' => CarbonInterval::minutes($value),
            'h' => CarbonInterval::hours($value),
            'd' => CarbonInterval::days($value),
            'w' => CarbonInterval::weeks($value),
            default => CarbonInterval::hour(),
        };
    }

    /** Whether the mutating tools are allowed to act. */
    protected function writesEnabled(): bool
    {
        return (bool) config('vigilance.mcp.allow_writes', false);
    }

    /** Whether manual control (job dispatch / command run) is enabled at all. */
    protected function controlEnabled(): bool
    {
        return (bool) config('vigilance.control.enabled', false);
    }

    /**
     * Decode a JSON-object argument string into an associative array. Returns []
     * for an empty string and null for malformed input (so the caller can fail
     * with a clear message).
     *
     * @return array<string, mixed>|null
     */
    protected function decodeObject(string $json): ?array
    {
        $json = trim($json);

        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Identify the actor for the audit log. Over local stdio there is no
     * authenticated user, so writes are attributed to "mcp"; over an
     * authenticated web transport the user's identifier is appended.
     */
    protected function actor(Request $request): string
    {
        $id = Vigilance::currentUser($request);

        return $id !== null && $id !== '' ? 'mcp:'.$id : 'mcp';
    }

    /**
     * The canonical Vigilance tool result: a compact JSON document the agent
     * reads directly. Everything in $data must already be redacted/truncated.
     *
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data): Response
    {
        return Response::text((string) json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ));
    }
}
