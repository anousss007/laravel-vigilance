<?php

namespace Vigilance\Control;

use Illuminate\Support\Facades\Session;
use Throwable;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Vigilance;

/**
 * Stage control-plane actions, review them together, then apply the batch.
 *
 * Every control action today fires the instant it is clicked. That is fine for
 * one of them and poor for several: pausing four queues is four separate
 * production changes with no moment in between to notice you picked the wrong
 * connection, and no record that they were meant as one operation.
 *
 * Staging turns them into a reviewable list. The list is per-session (it is a
 * draft belonging to one operator, not shared state), and applying it is the
 * only thing that touches production.
 */
class StagedChanges
{
    protected const SESSION_KEY = 'vigilance.staged';

    public const ACTIONS = [
        'pause' => 'Pause all supervisors',
        'resume' => 'Resume all supervisors',
        'restart' => 'Gracefully restart all workers',
        'pause_queue' => 'Pause queue',
        'resume_queue' => 'Resume queue',
    ];

    public function __construct(protected ControlPlane $control) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function stage(string $action, array $payload = []): void
    {
        if (! array_key_exists($action, self::ACTIONS)) {
            return;
        }

        $pending = $this->pending();

        // Staging the same thing twice is a slip, not an intent to do it twice.
        $signature = $this->signature($action, $payload);

        foreach ($pending as $change) {
            if ($this->signature($change['action'], $change['payload']) === $signature) {
                return;
            }
        }

        $pending[] = [
            'action' => $action,
            'payload' => $payload,
            'label' => $this->describe($action, $payload),
        ];

        Session::put(self::SESSION_KEY, $pending);
    }

    public function discard(int $index): void
    {
        $pending = $this->pending();

        unset($pending[$index]);

        Session::put(self::SESSION_KEY, array_values($pending));
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * @return list<array{action: string, payload: array<string, mixed>, label: string}>
     */
    public function pending(): array
    {
        $pending = Session::get(self::SESSION_KEY, []);

        return is_array($pending) ? array_values($pending) : [];
    }

    public function count(): int
    {
        return count($this->pending());
    }

    /**
     * Apply every staged change, in the order it was staged.
     *
     * A failure part-way is reported rather than rolled back: these are
     * side-effecting operations on a live fleet, and pretending they can be
     * undone would be a lie. The applied ones stay applied and the batch is
     * cleared, so a retry does not re-run what already succeeded.
     *
     * @return array{applied: int, failed: list<string>}
     */
    public function apply(): array
    {
        $applied = 0;
        $failed = [];
        $user = Vigilance::currentUser();
        $audit = new AuditLogger;

        foreach ($this->pending() as $change) {
            try {
                $this->run($change['action'], $change['payload']);
                $applied++;

                $audit->log(
                    action: 'staged:'.$change['action'],
                    subject: $change['label'],
                    meta: $change['payload'],
                    user: $user,
                );
            } catch (Throwable $e) {
                $failed[] = $change['label'].' — '.$e->getMessage();
            }
        }

        $this->clear();

        return ['applied' => $applied, 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function run(string $action, array $payload): void
    {
        match ($action) {
            'pause' => $this->control->pause(),
            'resume' => $this->control->continue(),
            'restart' => $this->control->restart(),
            'pause_queue' => $this->control->pauseQueue(
                (string) ($payload['connection'] ?? ''),
                (string) ($payload['queue'] ?? ''),
                isset($payload['seconds']) ? (int) $payload['seconds'] : null,
            ),
            'resume_queue' => $this->control->continueQueue(
                (string) ($payload['connection'] ?? ''),
                (string) ($payload['queue'] ?? ''),
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function describe(string $action, array $payload): string
    {
        $label = self::ACTIONS[$action] ?? $action;

        if (isset($payload['connection'], $payload['queue'])) {
            $label .= ' '.$payload['connection'].':'.$payload['queue'];
        }

        if (! empty($payload['seconds'])) {
            $label .= ' for '.$payload['seconds'].'s';
        }

        return $label;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function signature(string $action, array $payload): string
    {
        ksort($payload);

        return $action.'|'.json_encode($payload);
    }
}
