<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Vigilance\Http\Livewire\Tags;
use Vigilance\Metrics\Workload;
use Vigilance\Models\MonitoredTag;
use Vigilance\Models\RunTag;
use Vigilance\Notifications\LongWaitNotifier;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(fn () => Vigilance::auth(fn () => true));

it('lists tags and toggles monitoring', function () {
    RunTag::query()->insert([
        ['run_id' => 1, 'tag' => 'invoices', 'created_at' => now()],
        ['run_id' => 2, 'tag' => 'invoices', 'created_at' => now()],
    ]);

    Livewire::test(Tags::class)
        ->assertOk()
        ->assertSee('invoices')
        ->call('monitor', 'invoices');

    expect(MonitoredTag::query()->whereKey('invoices')->exists())->toBeTrue();

    Livewire::test(Tags::class)->call('unmonitor', 'invoices');

    expect(MonitoredTag::query()->whereKey('invoices')->exists())->toBeFalse();
});

it('sends a long-wait alert when a queue backs up, then throttles it', function () {
    Http::fake();
    config()->set('vigilance.notifications.long_wait_seconds', 60);
    Vigilance::routeSlackNotificationsTo('https://hooks.slack.test/abc');

    $workload = new class extends Workload
    {
        public function __construct() {}

        public function queues(): array
        {
            return [[
                'connection_name' => 'database', 'queue' => 'default', 'depth' => 200,
                'workers' => 1, 'processed_last_hour' => 0, 'failed_last_hour' => 0,
                'avg_runtime_ms' => 600, 'avg_wait_ms' => null, 'time_to_clear_ms' => 120_000,
                'series' => collect(),
            ]];
        }
    };

    $notifier = new LongWaitNotifier($workload);

    expect($notifier->check())->toBe(1);
    Http::assertSentCount(1);

    // Throttled on the next check (within the throttle window).
    expect($notifier->check())->toBe(0);
    Http::assertSentCount(1);
});
