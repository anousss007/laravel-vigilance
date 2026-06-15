<?php

namespace Vigilance\Http\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;
use Vigilance\Control\JobRetrier;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;
use Vigilance\Vigilance;

/**
 * Full view of a single issue (failure group): exception, stack-trace sample,
 * request context, occurrence trend and any captured runs — with resolve /
 * reopen / acknowledge / mute / prioritise / retry actions.
 */
class IssueDetail extends Component
{
    #[Locked]
    public int $issueId;

    public function mount(FailureGroup $group): void
    {
        $this->issueId = $group->getKey();
    }

    public function resolve(): void
    {
        FailureGroup::query()->whereKey($this->issueId)->update(['resolved_at' => now()]);
        $this->flash('Issue resolved.');
    }

    public function reopen(): void
    {
        FailureGroup::query()->whereKey($this->issueId)->update(['resolved_at' => null]);
        $this->flash('Issue reopened.');
    }

    public function acknowledge(): void
    {
        FailureGroup::query()->whereKey($this->issueId)->update([
            'acknowledged_at' => now(),
            'assignee' => Vigilance::currentUser(),
        ]);
        $this->flash('Issue acknowledged.');
    }

    public function mute(int $hours = 24): void
    {
        FailureGroup::query()->whereKey($this->issueId)->update(['muted_until' => now()->addHours($hours)]);
        $this->flash('Issue muted for '.$hours.'h.');
    }

    public function unmute(): void
    {
        FailureGroup::query()->whereKey($this->issueId)->update(['muted_until' => null]);
        $this->flash('Issue unmuted.');
    }

    public function setPriority(string $priority): void
    {
        $priority = in_array($priority, ['low', 'normal', 'high', 'critical'], true) ? $priority : 'normal';

        FailureGroup::query()->whereKey($this->issueId)->update(['priority' => $priority]);
        $this->flash("Priority set to {$priority}.");
    }

    public function retryGroup(): void
    {
        $result = app(JobRetrier::class)->retryGroup($this->issueId, Vigilance::currentUser());

        $this->flash("Retried {$result['retried']} job(s)".($result['skipped'] > 0 ? ", {$result['skipped']} skipped (no stored payload)" : '').'.');
    }

    protected function flash(string $message): void
    {
        session()->flash('vigilance.flash', ['type' => 'success', 'message' => $message]);
    }

    public function render()
    {
        $issue = FailureGroup::query()->findOrFail($this->issueId);

        $runs = Run::query()
            ->where('failure_group_id', $issue->getKey())
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('vigilance::pages.issue-detail', [
            'issue' => $issue,
            'runs' => $runs,
        ])->layout('vigilance::layout', ['title' => 'Issue · '.class_basename($issue->exception_class ?: 'unknown')]);
    }
}
