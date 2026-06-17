<?php

namespace Vigilance\Tests;

use Illuminate\Support\Str;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;
use Livewire\LivewireServiceProvider;
use Vigilance\Control\ControlGate;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Mcp\VigilanceServer;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;
use Vigilance\Tests\Fixtures\SampleJob;
use Vigilance\Vigilance;
use Vigilance\VigilanceServiceProvider;

/**
 * Boots the app with laravel/mcp registered alongside Vigilance so the MCP
 * tools can be invoked through the package's own testing harness
 * (VigilanceServer::tool(...)). Provides small seeders for the data the tools
 * read and mutate.
 */
class McpTestCase extends TestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            McpServiceProvider::class,
            VigilanceServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vigilance.mcp.enabled', true);
        // Writes are opt-in; individual tests enable them explicitly.
        $app['config']->set('vigilance.mcp.allow_writes', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVigilanceAuthState();
    }

    protected function tearDown(): void
    {
        $this->resetVigilanceAuthState();

        parent::tearDown();
    }

    /**
     * Vigilance keeps its auth callback and user resolver in static properties.
     * Another test elsewhere in the suite may set a resolver and never clear it,
     * which would otherwise leak into the MCP actor attribution. Reset them so
     * these tests are hermetic (no authenticated user → actor is "mcp").
     */
    private function resetVigilanceAuthState(): void
    {
        foreach (['authUsing', 'userResolver'] as $property) {
            $ref = new \ReflectionProperty(Vigilance::class, $property);
            $ref->setAccessible(true);
            $ref->setValue(null, null);
        }

        // ControlGate caches its allowed-jobs list in a static; clear it so a
        // test's control config isn't shadowed by an earlier test's cache.
        ControlGate::flush();
    }

    /**
     * @param  class-string<Tool>  $tool
     * @param  array<string, mixed>  $arguments
     */
    protected function tool(string $tool, array $arguments = []): TestResponse
    {
        return VigilanceServer::tool($tool, $arguments);
    }

    /** @param  array<string, mixed>  $attrs */
    protected function seedIssue(array $attrs = []): FailureGroup
    {
        return FailureGroup::query()->create(array_merge([
            'signature' => Str::random(64),
            'type' => 'job',
            'name' => SampleJob::class,
            'exception_class' => 'RuntimeException',
            'message' => 'boom',
            'occurrences' => 3,
            'source' => 'queue',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
        ], $attrs));
    }

    /** @param  array<string, mixed>  $attrs */
    protected function seedRun(array $attrs = []): Run
    {
        return Run::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'type' => RunType::Job->value,
            'name' => SampleJob::class,
            'status' => RunStatus::Succeeded->value,
            'finished_at' => now(),
        ], $attrs));
    }

    protected function seedFailedJobRun(int $amount = 1, ?int $groupId = null): Run
    {
        return Run::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => RunType::Job->value,
            'name' => SampleJob::class,
            'status' => RunStatus::Failed->value,
            'failure_group_id' => $groupId,
            'payload_raw' => serialize(new SampleJob($amount)),
            'finished_at' => now(),
        ]);
    }
}
