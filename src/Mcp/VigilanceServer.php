<?php

namespace Vigilance\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;
use Vigilance\Mcp\Tools\AcknowledgeIssueTool;
use Vigilance\Mcp\Tools\BatchesTool;
use Vigilance\Mcp\Tools\CacheTool;
use Vigilance\Mcp\Tools\CustomMetricsTool;
use Vigilance\Mcp\Tools\DispatchableJobsTool;
use Vigilance\Mcp\Tools\DispatchJobTool;
use Vigilance\Mcp\Tools\ExceptionsTool;
use Vigilance\Mcp\Tools\IncidentsTool;
use Vigilance\Mcp\Tools\IssuesTool;
use Vigilance\Mcp\Tools\IssueTool;
use Vigilance\Mcp\Tools\JobMetricsTool;
use Vigilance\Mcp\Tools\LogsTool;
use Vigilance\Mcp\Tools\MuteIssueTool;
use Vigilance\Mcp\Tools\OverviewTool;
use Vigilance\Mcp\Tools\PendingTool;
use Vigilance\Mcp\Tools\PerformanceTool;
use Vigilance\Mcp\Tools\QueuesTool;
use Vigilance\Mcp\Tools\ReleasesTool;
use Vigilance\Mcp\Tools\ReopenIssueTool;
use Vigilance\Mcp\Tools\ResolveIssueTool;
use Vigilance\Mcp\Tools\RetryIssueTool;
use Vigilance\Mcp\Tools\RetryRunTool;
use Vigilance\Mcp\Tools\RunCommandTool;
use Vigilance\Mcp\Tools\RunnableCommandsTool;
use Vigilance\Mcp\Tools\RunsTool;
use Vigilance\Mcp\Tools\RunTool;
use Vigilance\Mcp\Tools\ScheduleTool;
use Vigilance\Mcp\Tools\ServersTool;
use Vigilance\Mcp\Tools\SlosTool;
use Vigilance\Mcp\Tools\SlowHttpTool;
use Vigilance\Mcp\Tools\SlowJobsTool;
use Vigilance\Mcp\Tools\SlowQueriesTool;
use Vigilance\Mcp\Tools\SlowRequestsTool;
use Vigilance\Mcp\Tools\TagsTool;
use Vigilance\Mcp\Tools\TracesTool;
use Vigilance\Mcp\Tools\TraceTool;
use Vigilance\Mcp\Tools\UsageTool;
use Vigilance\Mcp\Tools\VitalsTool;
use Vigilance\Mcp\Tools\WorkersTool;
use Vigilance\Vigilance;

/**
 * The Vigilance MCP server: exposes this application's observability data —
 * errors, queue/command/scheduler runs, APM performance, traces, logs, SLOs,
 * incidents and release health — to an AI agent over the Model Context Protocol.
 *
 * Registered (when config vigilance.mcp.enabled is true) as a local stdio server
 * named per config('vigilance.mcp.name'); run it with `php artisan mcp:start
 * vigilance`. Read tools are always available; the write tools self-gate on
 * config('vigilance.mcp.allow_writes') via their shouldRegister().
 */
#[Name('Vigilance')]
class VigilanceServer extends Server
{
    protected string $instructions = <<<'MARKDOWN'
        This server exposes the Vigilance observability data for THIS Laravel
        application so you can investigate and fix problems against live data:
        grouped errors/exceptions (issues), queue/command/scheduler runs and
        failures, HTTP performance (slow routes with p50/p95/p99), slow database
        queries and slow jobs, server resource usage, request/job traces,
        application logs, SLOs and error budgets, incidents, and release/deploy
        health.

        Start with "overview" for a health summary, then drill in:
        - "issues" then "issue" — an error's stacktrace, context and the runs that triggered it.
        - "runs" then "run" — a failed job/command's parameters, output and exception.
        - "performance", "slow-queries", "slow-jobs", "servers" — latency and resource hotspots.
        - "traces" then "trace" — a single request/job waterfall with its correlated logs.
        - "logs", "slos", "incidents", "releases" — logs, error budgets, open incidents, deploy health.

        Tools are read-only unless the operator enabled writes; when enabled you
        may also resolve / acknowledge / mute an issue, reopen it, and retry a
        failed job (each is recorded in Vigilance's audit log). Output is redacted
        and truncated, so an absent or shortened field may simply be capped.
        MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        // Read-only.
        OverviewTool::class,
        IssuesTool::class,
        IssueTool::class,
        RunsTool::class,
        RunTool::class,
        PerformanceTool::class,
        SlowQueriesTool::class,
        SlowJobsTool::class,
        ServersTool::class,
        SlowRequestsTool::class,
        SlowHttpTool::class,
        CacheTool::class,
        ExceptionsTool::class,
        UsageTool::class,
        TracesTool::class,
        TraceTool::class,
        LogsTool::class,
        VitalsTool::class,
        SlosTool::class,
        IncidentsTool::class,
        ReleasesTool::class,
        CustomMetricsTool::class,
        // Operational (workers, queues, scheduler, batches, tags).
        WorkersTool::class,
        QueuesTool::class,
        PendingTool::class,
        JobMetricsTool::class,
        ScheduleTool::class,
        BatchesTool::class,
        TagsTool::class,
        // Write (each self-gates on vigilance.mcp.allow_writes).
        ResolveIssueTool::class,
        AcknowledgeIssueTool::class,
        MuteIssueTool::class,
        ReopenIssueTool::class,
        RetryRunTool::class,
        RetryIssueTool::class,
        // Manual control: discovery tools gate on control.enabled; the
        // dispatch/run tools additionally require mcp.allow_writes.
        DispatchableJobsTool::class,
        RunnableCommandsTool::class,
        DispatchJobTool::class,
        RunCommandTool::class,
    ];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        // Keep the advertised server version in lock-step with the single
        // canonical Vigilance version, rather than hard-coding a fourth copy.
        $this->version = Vigilance::$version;
    }
}
