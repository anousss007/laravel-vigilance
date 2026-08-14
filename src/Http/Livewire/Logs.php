<?php

namespace Vigilance\Http\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Vigilance\Http\Livewire\Concerns\ListensForUpdates;
use Vigilance\Logs\Contracts\LogStorage;
use Vigilance\Logs\LogLevel;

/**
 * The log explorer: a searchable, level- and channel-filterable feed of captured
 * application logs. Rows carry a link to the trace they were emitted in, and the
 * page can be scoped to a single trace via the "trace" query parameter (the
 * deep-link used from a trace's detail page).
 */
class Logs extends Component
{
    use ListensForUpdates;

    #[Url(as: 'q')]
    public string $q = '';

    #[Url(as: 'level')]
    public string $level = '';

    #[Url(as: 'channel')]
    public string $channel = '';

    #[Url(as: 'trace')]
    public string $trace = '';

    public function clear(): void
    {
        $this->reset(['q', 'level', 'channel', 'trace']);
    }

    public function render()
    {
        $filters = [];

        if ($this->q !== '') {
            $filters['q'] = $this->q;
        }

        if ($this->level !== '' && isset(LogLevel::VALUES[$this->level])) {
            $filters['min_level'] = LogLevel::value($this->level);
        }

        if ($this->channel !== '') {
            $filters['channel'] = $this->channel;
        }

        if ($this->trace !== '') {
            $filters['trace_id'] = $this->trace;
        }

        $storage = app(LogStorage::class);

        return view('vigilance::pages.logs', [
            'logs' => $storage->search($filters, 150),
            'channels' => $storage->channels(),
            'levels' => array_keys(LogLevel::VALUES),
            'enabled' => (bool) config('vigilance.logs.enabled', false),
        ])->layout('vigilance::layout', ['title' => 'Logs']);
    }
}
