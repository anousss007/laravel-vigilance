<?php

namespace Vigilance\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A bounded, per-unit-of-work trail of events leading up to a failure — the
 * "what happened just before the error" that turns a bare stack trace into a
 * story. Application code adds crumbs via Vigilance::breadcrumb(), and log lines
 * are recorded automatically. On an exception the trail is attached to the
 * issue's context (latest-wins, Sentry-style).
 *
 * Held in a ring buffer (oldest dropped past the cap) and cleared at each
 * request/job boundary so trails never leak across units of work.
 */
class Breadcrumbs
{
    /** @var list<array{t:string, level:string, category:?string, message:string, data:array<string,mixed>}> */
    protected array $crumbs = [];

    public function __construct(protected int $max = 25) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function add(string $message, ?string $category = null, string $level = 'info', array $data = []): void
    {
        $this->crumbs[] = [
            't' => Carbon::now()->toIso8601String(),
            'level' => $level,
            'category' => $category,
            'message' => Str::limit($message, 500),
            'data' => $data === [] ? [] : Redactor::redact($data),
        ];

        $overflow = count($this->crumbs) - max(1, $this->max);
        if ($overflow > 0) {
            $this->crumbs = array_slice($this->crumbs, $overflow);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->crumbs;
    }

    public function clear(): void
    {
        $this->crumbs = [];
    }

    /**
     * The context fragment to merge into a stored issue — empty when there is no
     * trail, so nothing is written for errors that had no preceding activity.
     *
     * @return array<string, mixed>
     */
    public function contextFragment(): array
    {
        return $this->crumbs === [] ? [] : ['breadcrumbs' => $this->crumbs];
    }
}
