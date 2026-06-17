<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Mcp\Tools\DispatchableJobsTool;
use Vigilance\Mcp\Tools\DispatchJobTool;
use Vigilance\Mcp\Tools\RunCommandTool;
use Vigilance\Mcp\Tools\RunnableCommandsTool;
use Vigilance\Tests\Fixtures\SampleJob;

uses(RefreshDatabase::class);

it('hides every manual-control tool when control is disabled', function () {
    config()->set('vigilance.control.enabled', false);
    config()->set('vigilance.mcp.allow_writes', true);

    $this->tool(DispatchableJobsTool::class)->assertHasErrors();
    $this->tool(RunnableCommandsTool::class)->assertHasErrors();
    $this->tool(DispatchJobTool::class, ['job' => SampleJob::class])->assertHasErrors();
    $this->tool(RunCommandTool::class, ['command' => 'env'])->assertHasErrors();
});

it('exposes discovery but not the write tools when control is on and writes are off', function () {
    config()->set('vigilance.control.enabled', true);
    config()->set('vigilance.mcp.allow_writes', false);
    config()->set('vigilance.control.jobs.mode', 'list');
    config()->set('vigilance.control.jobs.allow', [SampleJob::class]);

    $this->tool(DispatchableJobsTool::class)->assertOk()->assertSee('SampleJob');
    $this->tool(RunnableCommandsTool::class)->assertOk();

    $this->tool(DispatchJobTool::class, ['job' => SampleJob::class])->assertHasErrors();
    $this->tool(RunCommandTool::class, ['command' => 'env'])->assertHasErrors();
});

it('describes an allowlisted job\'s parameters', function () {
    config()->set('vigilance.control.enabled', true);
    config()->set('vigilance.control.jobs.mode', 'list');
    config()->set('vigilance.control.jobs.allow', [SampleJob::class]);

    $this->tool(DispatchableJobsTool::class, ['job' => SampleJob::class])
        ->assertOk()
        ->assertSee('amount')
        ->assertSee('label');
});

it('dispatches an allowlisted job and audits it', function () {
    config()->set('vigilance.control.enabled', true);
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.control.jobs.mode', 'list');
    config()->set('vigilance.control.jobs.allow', [SampleJob::class]);

    $this->tool(DispatchJobTool::class, [
        'job' => SampleJob::class,
        'arguments' => '{"amount": 7}',
        'queued' => false,
    ])->assertOk()->assertSee('"ok":true');

    $this->assertDatabaseHas('vigilance_audit', [
        'action' => 'dispatch_job',
        'subject' => SampleJob::class,
        'user' => 'mcp',
    ]);
});

it('refuses to dispatch a job that is not allowlisted', function () {
    config()->set('vigilance.control.enabled', true);
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.control.jobs.mode', 'list');
    config()->set('vigilance.control.jobs.allow', []);

    $this->tool(DispatchJobTool::class, ['job' => SampleJob::class, 'queued' => false])
        ->assertHasErrors();
});

it('runs an allowlisted command and audits it', function () {
    config()->set('vigilance.control.enabled', true);
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.control.commands.mode', 'list');
    config()->set('vigilance.control.commands.allow', ['env']);

    $this->tool(RunCommandTool::class, ['command' => 'env', 'queued' => false])
        ->assertOk()
        ->assertSee('exit_code');

    $this->assertDatabaseHas('vigilance_audit', [
        'action' => 'run_command',
        'subject' => 'env',
        'user' => 'mcp',
    ]);
});

it('refuses to run a command that is not allowlisted', function () {
    config()->set('vigilance.control.enabled', true);
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.control.commands.mode', 'list');
    config()->set('vigilance.control.commands.allow', []);

    $this->tool(RunCommandTool::class, ['command' => 'env', 'queued' => false])
        ->assertHasErrors();
});
