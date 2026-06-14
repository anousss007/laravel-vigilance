<?php

namespace Vigilance\Http\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Vigilance\Contracts\MetricsRepository;

/**
 * Charted history for one scope (a job class or a queue): throughput, average
 * runtime and average wait, from the metric snapshots.
 */
class MetricDetail extends Component
{
    #[Url(as: 'type')]
    public string $type = 'job';

    #[Url(as: 'scope')]
    public string $scope = '';

    public function render()
    {
        abort_if($this->scope === '' || ! in_array($this->type, ['job', 'queue'], true), 404);

        return view('vigilance::pages.metric-detail', [
            'type' => $this->type,
            'scope' => $this->scope,
            'series' => app(MetricsRepository::class)->series($this->type, $this->scope, 60),
        ])->layout('vigilance::layout', ['title' => 'Metric']);
    }
}
