<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Mcp\Tools\CacheTool;
use Vigilance\Mcp\Tools\CustomMetricsTool;
use Vigilance\Mcp\Tools\ExceptionsTool;
use Vigilance\Mcp\Tools\SlowHttpTool;
use Vigilance\Mcp\Tools\SlowRequestsTool;
use Vigilance\Mcp\Tools\UsageTool;
use Vigilance\Mcp\Tools\VitalsTool;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

it('vitals reports Core Web Vitals per page', function () {
    $apm = app(Apm::class);
    $apm->record('web_vital', (string) json_encode(['lcp', '/checkout']), 2200)->max()->count();
    $apm->record('web_vital', (string) json_encode(['cls', '/checkout']), 80)->max()->count();
    $apm->ingest();

    $this->tool(VitalsTool::class, ['window' => '24h'])
        ->assertOk()
        ->assertSee('/checkout')
        ->assertSee('rating');
});

it('custom-metrics reports counters and gauges', function () {
    Vigilance::increment('signups', 4);
    Vigilance::gauge('cart_value', 250);
    app(Apm::class)->ingest();

    $this->tool(CustomMetricsTool::class, ['window' => '24h'])
        ->assertOk()
        ->assertSee('signups')
        ->assertSee('cart_value');
});

it('slow-requests lists the slowest routes by threshold', function () {
    $apm = app(Apm::class);
    $apm->record('slow_request', (string) json_encode(['GET', '/reports/heavy']), 1900)->max()->count();
    $apm->ingest();

    $this->tool(SlowRequestsTool::class, ['window' => '24h'])
        ->assertOk()
        ->assertSee('/reports/heavy');
});

it('slow-http lists the slowest outgoing calls', function () {
    $apm = app(Apm::class);
    $apm->record('slow_outgoing_request', (string) json_encode(['POST', 'https://api.stripe.test']), 1500)->max()->count();
    $apm->ingest();

    $this->tool(SlowHttpTool::class, ['window' => '24h'])
        ->assertOk()
        ->assertSee('api.stripe.test');
});

it('cache reports the hit-rate', function () {
    $apm = app(Apm::class);
    $apm->record('cache_hit', 'users:1', 1)->count();
    $apm->record('cache_hit', 'users:2', 1)->count();
    $apm->record('cache_miss', 'users:3', 1)->count();
    $apm->ingest();

    $this->tool(CacheTool::class, ['window' => '24h'])
        ->assertOk()
        ->assertSee('hit_rate_pct');
});

it('exceptions lists APM exception groups', function () {
    $apm = app(Apm::class);
    $apm->record('exception', (string) json_encode(['class' => 'RuntimeException', 'location' => 'app/X.php:10']), time())->max()->count();
    $apm->ingest();

    $this->tool(ExceptionsTool::class, ['window' => '24h'])
        ->assertOk()
        ->assertSee('RuntimeException');
});

it('usage lists top users by activity', function () {
    $apm = app(Apm::class);
    $apm->record('user_request', '42', 1)->count();
    $apm->record('user_job', '42', 1)->count();
    $apm->set('user', '42', (string) json_encode(['id' => 42, 'name' => 'Sam Carter']));
    $apm->ingest();

    $this->tool(UsageTool::class, ['window' => '24h'])
        ->assertOk()
        ->assertSee('Sam Carter');
});
