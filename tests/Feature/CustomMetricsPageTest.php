<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Vigilance\Apm\Apm;
use Vigilance\Http\Livewire\Custom;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

it('renders recorded custom metrics on the dashboard', function () {
    Vigilance::increment('orders', 5);
    app(Apm::class)->ingest();

    Livewire::test(Custom::class)
        ->assertSee('orders')
        ->assertSee('counter');
});

it('shows an empty state when no custom metrics exist', function () {
    Livewire::test(Custom::class)->assertSee('No custom metrics');
});
