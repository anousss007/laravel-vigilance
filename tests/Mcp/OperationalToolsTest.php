<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Enums\RunStatus;
use Vigilance\Mcp\Tools\BatchesTool;
use Vigilance\Mcp\Tools\JobMetricsTool;
use Vigilance\Mcp\Tools\PendingTool;
use Vigilance\Mcp\Tools\QueuesTool;
use Vigilance\Mcp\Tools\ScheduleTool;
use Vigilance\Mcp\Tools\TagsTool;
use Vigilance\Mcp\Tools\WorkersTool;
use Vigilance\Models\MonitoredTag;
use Vigilance\Models\RunTag;
use Vigilance\Models\ScheduledTaskMonitor;
use Vigilance\Models\SupervisorRecord;
use Vigilance\Models\WorkerRecord;

uses(RefreshDatabase::class);

it('workers lists live supervisors and their workers', function () {
    SupervisorRecord::query()->create([
        'name' => 'supervisor-1',
        'host' => 'web-1',
        'pid' => 1234,
        'status' => 'running',
        'connection' => 'redis',
        'queues' => 'default,emails',
        'balance' => 'auto',
        'processes' => 3,
        'last_heartbeat_at' => now(),
    ]);

    WorkerRecord::query()->create([
        'supervisor' => 'supervisor-1',
        'host' => 'web-1',
        'pid' => 5678,
        'connection' => 'redis',
        'queue' => 'emails',
        'status' => 'running',
        'last_heartbeat_at' => now(),
    ]);

    $this->tool(WorkersTool::class)
        ->assertOk()
        ->assertSee('supervisor-1')
        ->assertSee('web-1');
});

it('queues lists per-queue workload', function () {
    $this->seedRun(['queue' => 'emails', 'connection_name' => 'database', 'status' => RunStatus::Succeeded->value]);

    $this->tool(QueuesTool::class)
        ->assertOk()
        ->assertSee('emails');
});

it('pending runs cleanly and returns a connections list', function () {
    $this->seedRun(['queue' => 'default', 'connection_name' => 'sync', 'status' => RunStatus::Succeeded->value]);

    $this->tool(PendingTool::class)
        ->assertOk()
        ->assertSee('connections');
});

it('job-metrics reports per-job-class performance', function () {
    $this->seedRun(['name' => 'App\\Jobs\\SendInvoice', 'status' => RunStatus::Succeeded->value, 'duration_ms' => 120]);
    $this->seedRun(['name' => 'App\\Jobs\\SendInvoice', 'status' => RunStatus::Failed->value, 'duration_ms' => 90]);

    $this->tool(JobMetricsTool::class, ['window' => '24h'])
        ->assertOk()
        ->assertSee('SendInvoice');
});

it('schedule lists scheduled-task monitors with health flags', function () {
    ScheduledTaskMonitor::query()->create([
        'name' => 'app:cleanup',
        'type' => 'command',
        'cron_expression' => '0 0 * * *',
        'grace_time_minutes' => 5,
        'monitored' => true,
    ]);

    $this->tool(ScheduleTool::class)
        ->assertOk()
        ->assertSee('app:cleanup')
        ->assertSee('is_late');
});

it('batches degrades gracefully without a job_batches table', function () {
    $this->tool(BatchesTool::class)
        ->assertOk()
        ->assertSee('supported');
});

it('tags lists recent tags and flags monitored ones', function () {
    RunTag::query()->create(['run_id' => 1, 'tag' => 'billing', 'created_at' => now()]);
    RunTag::query()->create(['run_id' => 2, 'tag' => 'billing', 'created_at' => now()]);
    MonitoredTag::query()->create(['tag' => 'billing']);

    $this->tool(TagsTool::class)
        ->assertOk()
        ->assertSee('billing');
});
