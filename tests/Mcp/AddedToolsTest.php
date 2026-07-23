<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Mcp\Tools\AssignIssueTool;
use Vigilance\Mcp\Tools\RecordDeployTool;
use Vigilance\Mcp\Tools\RoutesTool;
use Vigilance\Mcp\Tools\WorkloadTool;
use Vigilance\Models\Deployment;

uses(RefreshDatabase::class);

it('routes tool runs read-only and returns a route list', function () {
    $this->tool(RoutesTool::class, ['window' => '1h'])
        ->assertOk()
        ->assertSee('routes');
});

it('workload tool returns system load and job-class breakdown', function () {
    $this->tool(WorkloadTool::class, [])
        ->assertOk()
        ->assertSee('job_classes');
});

it('record-deploy is hidden/refused without writes', function () {
    config()->set('vigilance.mcp.allow_writes', false);

    $this->tool(RecordDeployTool::class, ['release' => 'v1.0.0'])->assertHasErrors();

    expect(Deployment::query()->count())->toBe(0);
});

it('records a deployment over mcp and audits it', function () {
    config()->set('vigilance.mcp.allow_writes', true);

    $this->tool(RecordDeployTool::class, ['release' => 'v1.2.3', 'commit' => 'abc1234'])
        ->assertOk()
        ->assertSee('v1.2.3');

    expect(Deployment::query()->where('version', 'v1.2.3')->exists())->toBeTrue();
    $this->assertDatabaseHas('vigilance_audit', ['action' => 'record_deploy', 'user' => 'mcp']);
});

it('assigns and unassigns an issue over mcp', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    $issue = $this->seedIssue();

    $this->tool(AssignIssueTool::class, ['id' => $issue->id, 'assignee' => 'dev@team'])
        ->assertOk()
        ->assertSee('assigned');
    expect($issue->fresh()->assignee)->toBe('dev@team');

    $this->tool(AssignIssueTool::class, ['id' => $issue->id, 'assignee' => ''])
        ->assertOk()
        ->assertSee('unassigned');
    expect($issue->fresh()->assignee)->toBeNull();

    $this->assertDatabaseHas('vigilance_audit', ['action' => 'assign_issue']);
});
