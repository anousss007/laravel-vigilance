<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\Exceptions\InvalidParameter;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Control\JobDispatcher;

#[Description('Dispatch an allowlisted queued job with constructor arguments. Requires BOTH manual control (VIGILANCE_CONTROL_ENABLED=true) AND MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true); the job must be in the vigilance.control allowlist. Audited. Use "dispatchable-jobs" to discover allowed jobs and their parameters.')]
#[IsDestructive]
class DispatchJobTool extends Tool
{
    public function shouldRegister(): bool
    {
        return $this->controlEnabled() && $this->writesEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'job' => $schema->string()
                ->description('The fully-qualified job class to dispatch.')
                ->required(),
            'arguments' => $schema->string()
                ->description('Constructor arguments as a JSON object, keyed by parameter name (e.g. {"podcast": 12, "notify": true}). Models are resolved by id, enums by value.'),
            'queued' => $schema->boolean()
                ->description('Queue the job (true, default) or run it synchronously (false).'),
            'queue' => $schema->string()
                ->description('Override the queue name (when queued).'),
        ];
    }

    public function handle(Request $request, JobDispatcher $dispatcher): Response
    {
        if (! $this->controlEnabled() || ! $this->writesEnabled()) {
            return Response::error('Job dispatch requires both vigilance.control.enabled and vigilance.mcp.allow_writes to be true.');
        }

        $job = (string) $request->get('job');
        $arguments = $this->decodeObject((string) $request->get('arguments'));

        if ($arguments === null) {
            return Response::error('The "arguments" parameter must be a JSON object.');
        }

        $queued = (bool) ($request->get('queued') ?? true);
        $queue = trim((string) $request->get('queue'));

        try {
            $dispatcher->dispatch($job, $arguments, $queued, $queue !== '' ? $queue : null, $this->actor($request));
        } catch (NotAllowed|InvalidParameter $e) {
            return Response::error($e->getMessage());
        }

        return $this->json([
            'ok' => true,
            'job' => $job,
            'queued' => $queued,
            'queue' => $queue !== '' ? $queue : null,
        ]);
    }
}
