<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Enums\RunStatus;
use Vigilance\Mcp\Tools\AcknowledgeIssueTool;
use Vigilance\Mcp\Tools\MuteIssueTool;
use Vigilance\Mcp\Tools\ReopenIssueTool;
use Vigilance\Mcp\Tools\ResolveIssueTool;
use Vigilance\Mcp\Tools\RetryIssueTool;
use Vigilance\Mcp\Tools\RetryRunTool;

uses(RefreshDatabase::class);

it('hides and refuses write tools when writes are disabled', function () {
    config()->set('vigilance.mcp.allow_writes', false);
    $issue = $this->seedIssue();

    $this->tool(ResolveIssueTool::class, ['id' => $issue->id])->assertHasErrors();

    expect($issue->fresh()->resolved_at)->toBeNull();
});

it('resolves an issue and writes an audit entry when enabled', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    $issue = $this->seedIssue();

    $this->tool(ResolveIssueTool::class, ['id' => $issue->id])
        ->assertOk()
        ->assertSee('resolved');

    expect($issue->fresh()->resolved_at)->not->toBeNull();

    $this->assertDatabaseHas('vigilance_audit', [
        'action' => 'resolve_issue',
        'subject' => (string) $issue->id,
        'user' => 'mcp',
    ]);
});

it('acknowledges an issue and assigns the mcp actor', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    $issue = $this->seedIssue();

    $this->tool(AcknowledgeIssueTool::class, ['id' => $issue->id])
        ->assertOk()
        ->assertSee('acknowledged');

    expect($issue->fresh()->acknowledged_at)->not->toBeNull();
    expect($issue->fresh()->assignee)->toBe('mcp');
});

it('mutes an issue for the requested hours', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    $issue = $this->seedIssue();

    $this->tool(MuteIssueTool::class, ['id' => $issue->id, 'hours' => 6])
        ->assertOk()
        ->assertSee('muted');

    expect($issue->fresh()->muted_until)->not->toBeNull();
});

it('reopens a resolved issue', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    $issue = $this->seedIssue(['resolved_at' => now()]);

    $this->tool(ReopenIssueTool::class, ['id' => $issue->id])->assertOk();

    expect($issue->fresh()->resolved_at)->toBeNull();
});

it('retries a failed job run and audits it', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    $run = $this->seedFailedJobRun(3);

    $this->tool(RetryRunTool::class, ['id' => $run->id])
        ->assertOk()
        ->assertSee('retried_run_id');

    $this->assertDatabaseHas('vigilance_audit', [
        'action' => 'retry',
        'run_id' => $run->id,
    ]);
});

it('refuses to retry a run that did not fail', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    $run = $this->seedRun(['status' => RunStatus::Succeeded->value]);

    $this->tool(RetryRunTool::class, ['id' => $run->id])->assertHasErrors();
});

it('retries every failed job in an issue and resolves it', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    $issue = $this->seedIssue();
    $this->seedFailedJobRun(1, $issue->id);
    $this->seedFailedJobRun(2, $issue->id);

    $this->tool(RetryIssueTool::class, ['id' => $issue->id])
        ->assertOk()
        ->assertSee('"retried":2');

    expect($issue->fresh()->resolved_at)->not->toBeNull();
});
