<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Enums\RunStatus;
use Vigilance\Mcp\Tools\IssuesTool;
use Vigilance\Mcp\Tools\IssueTool;
use Vigilance\Mcp\Tools\RunTool;

uses(RefreshDatabase::class);

it('redacts secret-looking keys in run parameters', function () {
    $run = $this->seedRun([
        'status' => RunStatus::Failed->value,
        'parameters' => ['password' => 'hunter2', 'token' => 'abc123', 'amount' => 5],
    ]);

    $this->tool(RunTool::class, ['id' => $run->id])
        ->assertOk()
        ->assertSee('[redacted]')
        ->assertSee('"amount"')
        ->assertDontSee('hunter2')
        ->assertDontSee('abc123');
});

it('redacts secret-looking keys in issue context', function () {
    $issue = $this->seedIssue(['context' => ['api_key' => 'sk-live-secret', 'route' => '/checkout']]);

    $this->tool(IssueTool::class, ['id' => $issue->id])
        ->assertOk()
        ->assertSee('[redacted]')
        ->assertDontSee('sk-live-secret');
});

it('clamps the row count to max_results', function () {
    config()->set('vigilance.mcp.max_results', 2);

    foreach (range(1, 5) as $i) {
        $this->seedIssue(['message' => "capped-issue-{$i}"]);
    }

    $this->tool(IssuesTool::class, ['status' => 'open', 'limit' => 100])
        ->assertOk()
        ->assertSee('"count":2');
});

it('truncates fields longer than max_field_length', function () {
    config()->set('vigilance.mcp.max_field_length', 20);
    $issue = $this->seedIssue(['message' => str_repeat('A', 200)]);

    $this->tool(IssueTool::class, ['id' => $issue->id])
        ->assertOk()
        ->assertSee('truncated');
});
