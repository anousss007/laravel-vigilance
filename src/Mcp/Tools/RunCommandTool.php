<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\CommandRunner;
use Vigilance\Control\Exceptions\NotAllowed;

#[Description('Run an allowlisted artisan command with arguments/options. Requires BOTH manual control (VIGILANCE_CONTROL_ENABLED=true) AND MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true); the command must be allowed and is never a denied/destructive one. Audited. Use "runnable-commands" to discover what is allowed.')]
#[IsDestructive]
class RunCommandTool extends Tool
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
            'command' => $schema->string()
                ->description('The artisan command name to run (e.g. "cache:clear").')
                ->required(),
            'arguments' => $schema->string()
                ->description('Command arguments as a JSON object keyed by argument name.'),
            'options' => $schema->string()
                ->description('Command options as a JSON object keyed by option name (without leading --).'),
            'queued' => $schema->boolean()
                ->description('Queue the command (true) or run it synchronously (false, default).'),
        ];
    }

    public function handle(Request $request, CommandRunner $runner): Response
    {
        if (! $this->controlEnabled() || ! $this->writesEnabled()) {
            return Response::error('Running commands requires both vigilance.control.enabled and vigilance.mcp.allow_writes to be true.');
        }

        $command = (string) $request->get('command');
        $arguments = $this->decodeObject((string) $request->get('arguments'));
        $options = $this->decodeObject((string) $request->get('options'));

        if ($arguments === null || $options === null) {
            return Response::error('The "arguments" and "options" parameters must be JSON objects.');
        }

        $queued = (bool) ($request->get('queued') ?? false);

        try {
            $result = $runner->run($command, $arguments, $options, $queued, $this->actor($request));
        } catch (NotAllowed $e) {
            return Response::error($e->getMessage());
        }

        if (isset($result['output'])) {
            $result['output'] = $this->truncate($result['output']);
        }

        return $this->json(array_merge(['ok' => true, 'command' => $command], $result));
    }
}
