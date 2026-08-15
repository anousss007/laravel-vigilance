<?php

namespace Vigilance\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;
use Vigilance\Capture\JobCapture;
use Vigilance\VigilanceServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        $this->resetCaptureRegistration();

        parent::setUp();
    }

    private function resetCaptureRegistration(): void
    {
        $prop = new ReflectionProperty(JobCapture::class, 'payloadCallbackRegistered');
        $prop->setValue(null, false);
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            VigilanceServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // The encrypter (and therefore Livewire's snapshot signing) needs a key,
        // and Testbench only supplies a default on some versions. Generate one
        // per run instead of committing a literal: nothing outlives the suite,
        // and a base64-shaped string in a public repository is a standing
        // secret-scanner alert for a credential that protects nothing.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Default to in-memory SQLite, but allow the suite to run against a real
        // MySQL/MariaDB/Postgres by setting VIGILANCE_TEST_DB (+ DB_* env) — so
        // the driver-specific storage SQL is exercised, not just SQLite.
        $driver = env('VIGILANCE_TEST_DB', 'sqlite');

        $connection = match ($driver) {
            'mysql', 'mariadb' => [
                'driver' => $driver,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'vigilance_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'prefix' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'vigilance_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        };

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);

        $app['config']->set('queue.default', 'sync');
        $app['config']->set('vigilance.enabled', true);
    }
}
