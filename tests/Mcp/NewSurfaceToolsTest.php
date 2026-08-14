<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Mcp\Tools\FootprintTool;
use Vigilance\Mcp\Tools\IncidentModeTool;
use Vigilance\Mcp\Tools\SuppressionsTool;
use Vigilance\Models\Suppression;
use Vigilance\Support\IncidentMode;
use Vigilance\Support\Suppressions;

uses(RefreshDatabase::class);

beforeEach(function () {
    IncidentMode::flushState();
    Suppressions::forget();
});

afterEach(fn () => IncidentMode::disengage());

it('reports what the monitoring itself stores', function () {
    app(Apm::class)->record('request', 'GET /x', 100)->count();
    app(Apm::class)->ingest();

    $this->tool(FootprintTool::class, [])
        ->assertOk()
        ->assertSee('total_rows')
        ->assertSee('turn_down_with')
        ->assertSee('retention_breaches');
});

it('lists suppression rules without needing writes', function () {
    // An agent must be able to see the mute rules even on a read-only install:
    // otherwise "no data for this route" is indistinguishable from "muted".
    config()->set('vigilance.mcp.allow_writes', false);

    Suppression::query()->create(['scope' => 'route', 'pattern' => '/admin/*', 'action' => 'ignore']);

    $this->tool(SuppressionsTool::class, ['action' => 'list'])
        ->assertOk()
        ->assertSee('/admin/*');
});

it('refuses to create a suppression without writes', function () {
    config()->set('vigilance.mcp.allow_writes', false);

    $this->tool(SuppressionsTool::class, ['action' => 'create', 'scope' => 'route', 'pattern' => '/x'])
        ->assertHasErrors();

    expect(Suppression::query()->count())->toBe(0);
});

it('creates and removes a suppression when writes are on', function () {
    config()->set('vigilance.mcp.allow_writes', true);

    $this->tool(SuppressionsTool::class, [
        'action' => 'create',
        'scope' => 'route',
        'pattern' => '/checkout',
        'expires_in_minutes' => 30,
    ])->assertOk();

    $rule = Suppression::query()->firstOrFail();

    expect($rule->expires_at)->not->toBeNull();

    $this->tool(SuppressionsTool::class, ['action' => 'remove', 'id' => $rule->id])->assertOk();

    expect(Suppression::query()->count())->toBe(0);
});

it('rejects a scope it does not know', function () {
    config()->set('vigilance.mcp.allow_writes', true);

    $this->tool(SuppressionsTool::class, ['action' => 'create', 'scope' => 'nonsense', 'pattern' => '/x'])
        ->assertHasErrors();
});

it('reports incident mode status without needing writes', function () {
    config()->set('vigilance.mcp.allow_writes', false);

    $this->tool(IncidentModeTool::class, ['action' => 'status'])
        ->assertOk()
        ->assertSee('available');
});

it('refuses to engage incident mode when the capability is off', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.incident_mode.enabled', false);

    $this->tool(IncidentModeTool::class, ['action' => 'engage'])->assertHasErrors();

    expect(IncidentMode::active())->toBeFalse();
});

it('refuses to engage incident mode without writes', function () {
    config()->set('vigilance.mcp.allow_writes', false);
    config()->set('vigilance.incident_mode.enabled', true);

    $this->tool(IncidentModeTool::class, ['action' => 'engage'])->assertHasErrors();

    expect(IncidentMode::active())->toBeFalse();
});

it('engages and ends incident mode when both gates are open', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.incident_mode.enabled', true);

    $this->tool(IncidentModeTool::class, ['action' => 'engage', 'minutes' => 10])->assertOk();

    expect(IncidentMode::active())->toBeTrue();

    $this->tool(IncidentModeTool::class, ['action' => 'end'])->assertOk();

    expect(IncidentMode::active())->toBeFalse();
});

it('caps the duration an agent can ask for', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.incident_mode.enabled', true);
    config()->set('vigilance.incident_mode.max_minutes', 15);

    $this->tool(IncidentModeTool::class, ['action' => 'engage', 'minutes' => 100000])->assertOk();

    expect(IncidentMode::secondsRemaining())->toBeLessThanOrEqual(15 * 60);
});
