<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Vigilance\Http\Livewire\Slos;

uses(RefreshDatabase::class);

it('renders a configured SLO on the dashboard', function () {
    config()->set('vigilance.slos', [
        'avail' => ['name' => 'API availability', 'sli' => 'success_rate', 'target' => 99.9, 'window_days' => 7],
    ]);

    Livewire::test(Slos::class)
        ->assertSee('API availability')
        ->assertSee('no-data'); // no traffic seeded yet
});

it('shows an empty state when no SLOs are configured', function () {
    config()->set('vigilance.slos', []);

    Livewire::test(Slos::class)->assertSee('No SLOs defined');
});
