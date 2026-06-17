<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Control\ControlGate;
use Vigilance\Control\JobReflector;

#[Description('List the jobs you are allowed to dispatch from MCP (per the vigilance.control allowlist), so you know what to pass to "dispatch-job". Pass "job" to get one job\'s full constructor-parameter schema. Requires manual control enabled (VIGILANCE_CONTROL_ENABLED=true).')]
#[IsReadOnly]
class DispatchableJobsTool extends Tool
{
    public function shouldRegister(): bool
    {
        return $this->controlEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'job' => $schema->string()
                ->description('A job class to inspect; returns its constructor parameter schema. Omit to list all allowed jobs.'),
        ];
    }

    public function handle(Request $request, ControlGate $gate, JobReflector $reflector): Response
    {
        if (! $this->controlEnabled()) {
            return Response::error('Manual control is disabled. Set vigilance.control.enabled to enable it.');
        }

        $job = trim((string) $request->get('job'));

        if ($job !== '') {
            if (! $gate->isJobAllowed($job)) {
                return Response::error("Job [{$job}] is not in the dispatch allowlist.");
            }

            return $this->json([
                'job' => $job,
                'parameters' => $this->describeParameters($reflector->schema($job)),
            ]);
        }

        $jobs = array_map(fn (string $class): array => [
            'job' => $class,
            'short' => class_basename($class),
            'label' => property_exists($class, 'vigilanceLabel') ? (string) $class::$vigilanceLabel : null,
        ], $gate->jobs());

        return $this->json([
            'count' => count($jobs),
            'jobs' => $jobs,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<int, array<string, mixed>>
     */
    private function describeParameters(array $schema): array
    {
        return array_map(fn (array $p): array => [
            'name' => $p['name'] ?? null,
            'required' => $p['required'] ?? false,
            'type' => $p['builtin'] ?? $p['model_class'] ?? $p['enum_class'] ?? $p['type'] ?? null,
            'nullable' => $p['nullable'] ?? false,
            'default' => ($p['has_default'] ?? false) ? ($p['default'] ?? null) : null,
            'enum_options' => $p['enum_options'] ?? null,
            'is_model' => $p['is_model'] ?? false,
        ], $schema);
    }
}
