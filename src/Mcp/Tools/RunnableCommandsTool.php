<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Control\CommandReflector;
use Vigilance\Control\ControlGate;

#[Description('List the artisan commands you are allowed to run from MCP (per the vigilance.control allowlist). Pass "command" to get one command\'s arguments and options schema for "run-command". Requires manual control enabled (VIGILANCE_CONTROL_ENABLED=true).')]
#[IsReadOnly]
class RunnableCommandsTool extends Tool
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
            'command' => $schema->string()
                ->description('A command name to inspect; returns its arguments + options schema. Omit to list all allowed commands.'),
        ];
    }

    public function handle(Request $request, ControlGate $gate, CommandReflector $reflector): Response
    {
        if (! $this->controlEnabled()) {
            return Response::error('Manual control is disabled. Set vigilance.control.enabled to enable it.');
        }

        $command = trim((string) $request->get('command'));

        if ($command !== '') {
            if (! $gate->isCommandAllowed($command)) {
                return Response::error("Command [{$command}] is not in the run allowlist.");
            }

            return $this->json([
                'command' => $command,
                'schema' => $reflector->schema($command),
            ]);
        }

        $commands = $gate->commands();
        $limit = $this->resolveLimit();
        $shown = array_slice($commands, 0, $limit);

        return $this->json([
            'count' => count($shown),
            'total' => count($commands),
            'truncated' => count($commands) > count($shown),
            'commands' => $shown,
        ]);
    }
}
