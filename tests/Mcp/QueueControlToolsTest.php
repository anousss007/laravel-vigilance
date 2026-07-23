<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vigilance\Mcp\Tools\CancelPendingTool;
use Vigilance\Mcp\Tools\ClearQueueTool;
use Vigilance\Mcp\Tools\ControlWorkersTool;
use Vigilance\Mcp\Tools\PauseQueueTool;
use Vigilance\Mcp\Tools\ResumeQueueTool;
use Vigilance\Supervision\ControlPlane;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! Schema::hasTable('jobs')) {
        Schema::create('jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
    }

    // DDL auto-commits on MySQL, so purge to keep row-count assertions
    // deterministic across sqlite / mysql / postgres.
    DB::table('jobs')->delete();
});

// ── gating ───────────────────────────────────────────────────────────────────

it('refuses every worker/queue control tool when writes are disabled', function () {
    config()->set('vigilance.mcp.allow_writes', false);
    config()->set('vigilance.control.enabled', true);

    $this->tool(ControlWorkersTool::class, ['action' => 'pause'])->assertHasErrors();
    $this->tool(PauseQueueTool::class, ['queue' => 'emails'])->assertHasErrors();
    $this->tool(ClearQueueTool::class, ['connection' => 'database', 'queue' => 'default'])->assertHasErrors();

    expect(app(ControlPlane::class)->isPaused())->toBeFalse()
        ->and(app(ControlPlane::class)->isQueuePaused('database', 'emails'))->toBeFalse();
});

it('refuses destructive tools when manual control is disabled even with writes on', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.control.enabled', false);

    $this->tool(ClearQueueTool::class, ['connection' => 'database', 'queue' => 'default'])->assertHasErrors();
    $this->tool(CancelPendingTool::class, ['connection' => 'database', 'ids' => [1]])->assertHasErrors();
});

// ── fleet control ────────────────────────────────────────────────────────────

it('pauses and resumes the whole fleet over mcp and audits it', function () {
    config()->set('vigilance.mcp.allow_writes', true);

    $this->tool(ControlWorkersTool::class, ['action' => 'pause'])->assertOk()->assertSee('paused');
    expect(app(ControlPlane::class)->isPaused())->toBeTrue();

    $this->tool(ControlWorkersTool::class, ['action' => 'resume'])->assertOk();
    expect(app(ControlPlane::class)->status())->toBe(ControlPlane::RUNNING);

    $this->assertDatabaseHas('vigilance_audit', ['action' => 'pause_workers', 'user' => 'mcp']);
});

it('restarts the fleet over mcp', function () {
    config()->set('vigilance.mcp.allow_writes', true);

    $this->tool(ControlWorkersTool::class, ['action' => 'restart'])->assertOk();

    expect(app(ControlPlane::class)->restartToken())->not->toBeNull();
});

// ── per-queue control ────────────────────────────────────────────────────────

it('pauses a single queue over mcp (timed) and resumes it', function () {
    config()->set('vigilance.mcp.allow_writes', true);

    $this->tool(PauseQueueTool::class, ['queue' => 'emails', 'connection' => 'redis', 'seconds' => 300])
        ->assertOk()
        ->assertSee('emails');

    expect(app(ControlPlane::class)->isQueuePaused('redis', 'emails'))->toBeTrue();

    $this->tool(ResumeQueueTool::class, ['queue' => 'emails', 'connection' => 'redis'])->assertOk();

    expect(app(ControlPlane::class)->isQueuePaused('redis', 'emails'))->toBeFalse();
    $this->assertDatabaseHas('vigilance_audit', ['action' => 'pause_queue', 'subject' => 'redis:emails']);
});

it('defaults the connection to vigilance.defaults.connection', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.defaults.connection', 'sqs');

    $this->tool(PauseQueueTool::class, ['queue' => 'jobs'])->assertOk();

    expect(app(ControlPlane::class)->isQueuePaused('sqs', 'jobs'))->toBeTrue();
});

// ── destructive queue ops ────────────────────────────────────────────────────

it('clears a queue over mcp when fully enabled', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.control.enabled', true);
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']);

    foreach (['default', 'default', 'other'] as $q) {
        DB::table('jobs')->insert([
            'queue' => $q, 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);
    }

    $this->tool(ClearQueueTool::class, ['connection' => 'database', 'queue' => 'default'])
        ->assertOk()
        ->assertSee('"deleted":2');

    expect(DB::table('jobs')->count())->toBe(1);
    $this->assertDatabaseHas('vigilance_audit', ['action' => 'clear_queue', 'subject' => 'database:default']);
});

it('cancels specific pending jobs over mcp', function () {
    config()->set('vigilance.mcp.allow_writes', true);
    config()->set('vigilance.control.enabled', true);
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']);

    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $ids[] = DB::table('jobs')->insertGetId([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);
    }

    $this->tool(CancelPendingTool::class, ['connection' => 'database', 'ids' => [$ids[0], $ids[2]]])
        ->assertOk()
        ->assertSee('"deleted":2');

    expect(DB::table('jobs')->pluck('id')->all())->toBe([$ids[1]]);
});
