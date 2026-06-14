<?php

namespace Vigilance\Http\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Vigilance\Tracing\Contracts\TraceStorage;

/**
 * The traces list: recent request / job / command timelines, filterable by
 * type, status and slowness. Each row links to its waterfall detail.
 */
class Traces extends Component
{
    #[Url(as: 'type')]
    public string $type = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'slow')]
    public bool $slowOnly = false;

    #[Url(as: 'q')]
    public string $q = '';

    public function clear(): void
    {
        $this->reset(['type', 'status', 'slowOnly', 'q']);
    }

    public function render()
    {
        $filters = [];

        if ($this->type !== '') {
            $filters['type'] = $this->type;
        }

        if ($this->status !== '') {
            $filters['status'] = $this->status;
        }

        if ($this->slowOnly) {
            $filters['slow'] = (int) config('vigilance.tracing.slow_threshold', 1000);
        }

        if ($this->q !== '') {
            $filters['q'] = $this->q;
        }

        return view('vigilance::pages.traces', [
            'traces' => app(TraceStorage::class)->recent($filters, 75),
            'enabled' => (bool) config('vigilance.tracing.enabled', false),
            'sampleRate' => (float) config('vigilance.tracing.sample_rate', 0),
            'slowThreshold' => (int) config('vigilance.tracing.slow_threshold', 1000),
        ])->layout('vigilance::layout', ['title' => 'Traces']);
    }
}
