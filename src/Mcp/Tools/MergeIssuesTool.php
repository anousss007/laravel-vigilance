<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\IssueMerger;

#[Description('Merge one error issue into another when fingerprinting split the same problem into two. The source issue\'s occurrences and runs move to the target, the source is hidden, and future occurrences of its signature are redirected to the target. Requires MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true). Audited.')]
#[IsDestructive]
class MergeIssuesTool extends Tool
{
    public function shouldRegister(): bool
    {
        return $this->writesEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->integer()
                ->description('The source issue id to merge (it will be hidden).')
                ->required(),
            'into' => $schema->integer()
                ->description('The target/canonical issue id to merge into.')
                ->required(),
        ];
    }

    public function handle(Request $request, IssueMerger $merger): Response
    {
        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        try {
            $result = $merger->merge($request->integer('from'), $request->integer('into'), $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return $this->json(array_merge(['ok' => true], $result));
    }
}
