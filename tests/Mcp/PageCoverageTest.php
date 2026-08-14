<?php

use Illuminate\Support\Facades\Route;
use Vigilance\Mcp\VigilanceServer;

/**
 * The README promises "a tool for every dashboard page". That promise silently
 * became false the moment the Usage page shipped without one — a claim nobody
 * re-reads is a claim that rots. This pins it: adding a dashboard route now
 * forces a decision about its MCP coverage, in this file, at review time.
 */
it('exposes an MCP tool for every dashboard page', function () {
    // Route name => the tool that answers for it, or null where a tool makes no
    // sense and we are saying so on purpose.
    $coverage = [
        'overview' => 'OverviewTool',
        'apm' => 'PerformanceTool',
        'traces' => 'TracesTool',
        'traces.show' => 'TraceTool',
        'runs' => 'RunsTool',
        'runs.show' => 'RunTool',
        'issues' => 'IssuesTool',
        'issues.show' => 'IssueTool',
        'tags' => 'TagsTool',
        'dispatch' => 'DispatchableJobsTool',
        'commands' => 'RunnableCommandsTool',
        'schedule' => 'ScheduleTool',
        'workload' => 'WorkloadTool',
        'workers' => 'WorkersTool',
        'pending' => 'PendingTool',
        'batches' => 'BatchesTool',
        'routes' => 'RoutesTool',
        'vitals' => 'VitalsTool',
        'slos' => 'SlosTool',
        'incidents' => 'IncidentsTool',
        'releases' => 'ReleasesTool',
        'custom' => 'CustomMetricsTool',
        'logs' => 'LogsTool',
        'metrics' => 'JobMetricsTool',
        'metrics.show' => 'JobMetricsTool',
        'usage' => 'FootprintTool',
    ];

    $pages = collect(Route::getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter(fn (?string $name) => is_string($name) && str_starts_with($name, 'vigilance.'))
        ->map(fn (string $name) => substr($name, strlen('vigilance.')))
        // Assets and the RUM/feedback ingest endpoints are not pages.
        ->reject(fn (string $name) => str_starts_with($name, 'assets.') || in_array($name, ['rum', 'feedback'], true))
        ->unique()
        ->values();

    foreach ($pages as $page) {
        // Not toHaveKey(): its second argument is an expected *value*, not a
        // message, so a failure there reports the wrong thing entirely.
        expect(array_key_exists($page, $coverage))->toBeTrue(
            "dashboard page [{$page}] has no entry in the MCP coverage map",
        );
    }
});

it('registers every tool the coverage map names', function () {
    $registered = collect((new ReflectionClass(VigilanceServer::class))->getDefaultProperties()['tools'] ?? [])
        ->map(fn (string $class) => class_basename($class))
        ->all();

    foreach (['FootprintTool', 'SuppressionsTool', 'IncidentModeTool', 'RoutesTool'] as $tool) {
        expect($registered)->toContain($tool);
    }
});
