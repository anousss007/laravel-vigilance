<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Http\Livewire\MetricDetail;
use Vigilance\Http\Livewire\Metrics;
use Vigilance\Models\MetricSnapshot;
use Vigilance\Models\Run;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(fn () => Vigilance::auth(fn () => true));

it('renders the metrics index with job rows linking to detail', function () {
    Run::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\SendInvoice',
        'status' => RunStatus::Succeeded->value,
        'duration_ms' => 240,
    ]);

    Livewire::test(Metrics::class)
        ->assertOk()
        ->assertSee('App\\Jobs\\SendInvoice')
        ->assertSee('By job class')
        ->assertSee('By queue');
});

it('renders a metric detail with snapshot charts', function () {
    foreach (range(1, 5) as $i) {
        MetricSnapshot::query()->create([
            'scope_type' => 'job',
            'scope' => 'App\\Jobs\\SendInvoice',
            'throughput' => 10 * $i,
            'failures' => $i,
            'runtime_avg_ms' => 100 + $i,
            'wait_avg_ms' => 20 + $i,
            'measured_at' => now()->subMinutes(5 * (6 - $i)),
        ]);
    }

    Livewire::test(MetricDetail::class, ['type' => 'job', 'scope' => 'App\\Jobs\\SendInvoice'])
        ->assertOk()
        ->assertSee('Throughput')
        ->assertSee('Avg runtime')
        ->assertSee('App\\Jobs\\SendInvoice');
});

it('404s a metric detail without a scope', function () {
    Livewire::test(MetricDetail::class, ['type' => 'job', 'scope' => ''])->assertStatus(404);
});

it('returns 200 on the metrics route', function () {
    $this->get(route('vigilance.metrics'))->assertOk();
});
