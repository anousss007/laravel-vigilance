<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Enums\RunStatus;
use Vigilance\Mcp\Tools\IncidentsTool;
use Vigilance\Mcp\Tools\IssuesTool;
use Vigilance\Mcp\Tools\IssueTool;
use Vigilance\Mcp\Tools\LogsTool;
use Vigilance\Mcp\Tools\OverviewTool;
use Vigilance\Mcp\Tools\PerformanceTool;
use Vigilance\Mcp\Tools\ReleasesTool;
use Vigilance\Mcp\Tools\RunsTool;
use Vigilance\Mcp\Tools\RunTool;
use Vigilance\Mcp\Tools\ServersTool;
use Vigilance\Mcp\Tools\SlosTool;
use Vigilance\Mcp\Tools\SlowJobsTool;
use Vigilance\Mcp\Tools\SlowQueriesTool;
use Vigilance\Mcp\Tools\TracesTool;
use Vigilance\Models\Deployment;
use Vigilance\Models\Incident;
use Vigilance\Tests\Fixtures\SampleJob;

uses(RefreshDatabase::class);

it('overview summarises counts and recent failures', function () {
    $issue = $this->seedIssue(['message' => 'kaboom-overview']);
    $this->seedFailedJobRun(1, $issue->id);

    $this->tool(OverviewTool::class, ['window' => '24h'])
        ->assertOk()
        ->assertSee('kaboom-overview')
        ->assertSee('"failed"');
});

it('issues lists open issues and filters by status', function () {
    $this->seedIssue(['message' => 'open-issue-x']);
    $this->seedIssue(['message' => 'resolved-issue-y', 'resolved_at' => now()]);

    $this->tool(IssuesTool::class, ['status' => 'open'])
        ->assertOk()
        ->assertSee('open-issue-x')
        ->assertDontSee('resolved-issue-y');

    $this->tool(IssuesTool::class, ['status' => 'resolved'])
        ->assertOk()
        ->assertSee('resolved-issue-y')
        ->assertDontSee('open-issue-x');
});

it('issues searches the message', function () {
    $this->seedIssue(['message' => 'needle in the haystack']);
    $this->seedIssue(['message' => 'something else entirely']);

    $this->tool(IssuesTool::class, ['q' => 'needle'])
        ->assertOk()
        ->assertSee('needle in the haystack')
        ->assertDontSee('something else entirely');
});

it('issue returns detail with its triggering runs', function () {
    $issue = $this->seedIssue(['message' => 'detail-message']);
    $this->seedRun([
        'status' => RunStatus::Failed->value,
        'failure_group_id' => $issue->id,
        'exception_message' => 'run-level-exception',
    ]);

    $this->tool(IssueTool::class, ['id' => $issue->id])
        ->assertOk()
        ->assertSee('detail-message')
        ->assertSee('run-level-exception');
});

it('issue errors when the id is unknown', function () {
    $this->tool(IssueTool::class, ['id' => 999999])->assertHasErrors();
});

it('runs lists and filters by status', function () {
    $this->seedRun(['name' => 'App\\Jobs\\Alpha', 'status' => RunStatus::Failed->value]);
    $this->seedRun(['name' => 'App\\Jobs\\Beta', 'status' => RunStatus::Succeeded->value]);

    $this->tool(RunsTool::class, ['status' => 'failed'])
        ->assertOk()
        ->assertSee('Alpha')
        ->assertDontSee('Beta');
});

it('run returns full detail', function () {
    $run = $this->seedRun(['output' => 'the-command-output']);

    $this->tool(RunTool::class, ['id' => $run->id])
        ->assertOk()
        ->assertSee('the-command-output');
});

it('run errors when the id is unknown', function () {
    $this->tool(RunTool::class, ['id' => 424242])->assertHasErrors();
});

it('incidents lists open incidents', function () {
    Incident::query()->create([
        'key' => 'queue-backlog',
        'title' => 'Backlog growing fast',
        'message' => '',
        'level' => 'warning',
        'status' => 'open',
        'occurrences' => 2,
        'opened_at' => now()->subHour(),
        'last_seen_at' => now(),
    ]);

    $this->tool(IncidentsTool::class, ['status' => 'open'])
        ->assertOk()
        ->assertSee('Backlog growing fast');
});

it('releases lists deployments with a verdict', function () {
    Deployment::query()->create([
        'version' => 'v9.9.9-release',
        'environment' => 'production',
        'deployed_at' => now()->subMinutes(30),
    ]);

    $this->tool(ReleasesTool::class)
        ->assertOk()
        ->assertSee('v9.9.9-release')
        ->assertSee('verdict');
});

it('slos, servers, traces and logs run cleanly with no data', function () {
    $this->tool(SlosTool::class)->assertOk()->assertSee('slos');
    $this->tool(ServersTool::class)->assertOk()->assertSee('servers');
    $this->tool(TracesTool::class)->assertOk()->assertSee('traces');
    $this->tool(LogsTool::class)->assertOk()->assertSee('logs');
});

it('surfaces APM performance, slow queries and slow jobs', function () {
    $apm = app(Apm::class);
    $apm->record('request', (string) json_encode(['GET', '/orders']), 120)->max()->count();
    $apm->record('slow_query', (string) json_encode(['sql' => 'select * from orders', 'location' => 'OrderController.php:20']), 500)->max()->count();
    $apm->record('slow_job', SampleJob::class, 800)->max()->count();
    $apm->ingest();

    $this->tool(PerformanceTool::class, ['window' => '1h'])
        ->assertOk()
        ->assertSee('/orders');

    $this->tool(SlowQueriesTool::class, ['window' => '1h'])
        ->assertOk()
        ->assertSee('select * from orders');

    $this->tool(SlowJobsTool::class, ['window' => '1h'])
        ->assertOk()
        ->assertSee('SampleJob');
});
