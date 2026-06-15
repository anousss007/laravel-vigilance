<?php

namespace Vigilance;

use GuzzleHttp\Promise\RejectedPromise;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Console\CheckCommand;
use Vigilance\Apm\Console\WorkCommand;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Ingests\FanOutIngest;
use Vigilance\Apm\Ingests\NullIngest;
use Vigilance\Apm\Ingests\RedisIngest;
use Vigilance\Apm\Ingests\StorageIngest;
use Vigilance\Apm\Storage\DatabaseStorage;
use Vigilance\Capture\CommandCapture;
use Vigilance\Capture\IssueCapture;
use Vigilance\Capture\JobCapture;
use Vigilance\Capture\Recorder;
use Vigilance\Capture\ScheduleCapture;
use Vigilance\Console\ContinueCommand;
use Vigilance\Console\DeployCommand;
use Vigilance\Console\DoctorCommand;
use Vigilance\Console\HealthCommand;
use Vigilance\Console\InstallCommand;
use Vigilance\Console\PauseCommand;
use Vigilance\Console\PruneCommand;
use Vigilance\Console\RestartCommand;
use Vigilance\Console\ScheduleSyncCommand;
use Vigilance\Console\SnapshotCommand;
use Vigilance\Console\StatusCommand;
use Vigilance\Console\SuperviseCommand;
use Vigilance\Console\TerminateCommand;
use Vigilance\Contracts\MetricsRepository;
use Vigilance\Contracts\RunRepository;
use Vigilance\Control\ControlGate;
use Vigilance\Events\ExceptionReported;
use Vigilance\Http\Controllers\AssetController;
use Vigilance\Http\Livewire\Apm as ApmPage;
use Vigilance\Http\Livewire\ApmCard;
use Vigilance\Http\Livewire\Batches;
use Vigilance\Http\Livewire\CommandRunner;
use Vigilance\Http\Livewire\Dispatcher;
use Vigilance\Http\Livewire\Failures;
use Vigilance\Http\Livewire\IssueDetail;
use Vigilance\Http\Livewire\MetricDetail;
use Vigilance\Http\Livewire\Metrics;
use Vigilance\Http\Livewire\Overview;
use Vigilance\Http\Livewire\Pending;
use Vigilance\Http\Livewire\Routes;
use Vigilance\Http\Livewire\RunDetail;
use Vigilance\Http\Livewire\Runs;
use Vigilance\Http\Livewire\Schedule;
use Vigilance\Http\Livewire\Tags;
use Vigilance\Http\Livewire\TraceDetail;
use Vigilance\Http\Livewire\Traces;
use Vigilance\Http\Livewire\Workers;
use Vigilance\Http\Livewire\Workload;
use Vigilance\Http\Middleware\Authorize;
use Vigilance\Storage\DatabaseMetricsRepository;
use Vigilance\Storage\DatabaseRunRepository;
use Vigilance\Tracing\Contracts\TraceStorage;
use Vigilance\Tracing\Middleware\TraceRequests;
use Vigilance\Tracing\Sampling\Sampler;
use Vigilance\Tracing\Storage\DatabaseTraceStorage;
use Vigilance\Tracing\Tracer;

class VigilanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vigilance.php', 'vigilance');

        $this->app->singleton(RunRepository::class, DatabaseRunRepository::class);
        $this->app->singleton(MetricsRepository::class, DatabaseMetricsRepository::class);
        $this->app->singleton(Recorder::class);

        $this->registerApm();
    }

    protected function registerApm(): void
    {
        $this->app->singleton(Storage::class, DatabaseStorage::class);

        $this->app->singleton(Ingest::class, function ($app) {
            $primary = $this->makeIngest($app, (string) config('vigilance.apm.ingest.driver', 'storage'));

            $exporters = array_values(array_map(
                fn ($driver) => $this->makeIngest($app, (string) $driver),
                (array) config('vigilance.apm.ingest.exporters', []),
            ));

            return $exporters === [] ? $primary : new FanOutIngest($primary, $exporters);
        });

        $this->app->singleton(Apm::class, fn ($app) => new Apm($app));

        $this->registerTracing();
    }

    protected function registerTracing(): void
    {
        $this->app->singleton(TraceStorage::class, DatabaseTraceStorage::class);
        $this->app->singleton(Sampler::class);
        $this->app->singleton(Tracer::class, fn ($app) => new Tracer($app, $app->make(Sampler::class)));
    }

    /**
     * Resolve an APM ingest from a driver alias ('storage' | 'null') or a custom
     * class-string that implements the Ingest contract (the export seam).
     */
    protected function makeIngest(Application $app, string $driver): Ingest
    {
        if ($driver === 'storage') {
            return new StorageIngest($app->make(Storage::class));
        }

        if ($driver === 'null') {
            return new NullIngest;
        }

        if ($driver === 'redis') {
            return new RedisIngest($app->make(Storage::class));
        }

        $ingest = $app->make($driver);

        if (! $ingest instanceof Ingest) {
            throw new \InvalidArgumentException("APM ingest [{$driver}] must implement ".Ingest::class.'.');
        }

        return $ingest;
    }

    protected function bootApm(): void
    {
        if (! config('vigilance.apm.enabled', true)) {
            return;
        }

        $apm = $this->app->make(Apm::class);

        $apm->register((array) config('vigilance.apm.recorders', []));

        // Flush in the terminate phase — after the response is sent — so APM
        // never adds request latency.
        if ($this->app->runningInConsole()) {
            $this->app->make(Kernel::class)
                ->whenCommandLifecycleIsLongerThan(-1, fn () => $apm->ingest());
        } else {
            $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
                ->whenRequestLifecycleIsLongerThan(-1, fn () => $apm->ingest());
        }

        $events = $this->app->make('events');
        $events->listen(Looping::class, fn () => $apm->ingest());
        $events->listen(WorkerStopping::class, fn () => $apm->ingest());

        // Octane: re-bind the singleton to the current request sandbox.
        if (class_exists('Laravel\Octane\Events\RequestReceived')) {
            foreach ([
                'Laravel\Octane\Events\RequestReceived',
                'Laravel\Octane\Events\TaskReceived',
                'Laravel\Octane\Events\TickReceived',
            ] as $event) {
                $events->listen($event, fn ($e) => $apm->setContainer($e->sandbox));
            }
        }
    }

    public function boot(): void
    {
        $this->registerPublishing();

        // Migrations are auto-loaded so a plain `composer require` + `migrate`
        // works with zero configuration.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'vigilance');

        if (config('vigilance.enabled', true)) {
            $this->registerCapture();

            if (config('vigilance.issues.enabled', true)) {
                $this->registerIssueCapture();
            }
        }

        $this->registerAssets();
        $this->registerRoutes();
        $this->registerLivewire();
        $this->registerCommands();
        $this->registerAbout();
        $this->registerStateReset();
        $this->bootApm();
        $this->bootTracing();
    }

    /**
     * Route HTTP-request and manually-reported exceptions into the unified
     * Issues inbox. Queue/command failures are grouped by the run capture, so
     * we only cover the layers it doesn't (avoiding double counting).
     */
    protected function registerIssueCapture(): void
    {
        $capture = $this->app->make(IssueCapture::class);

        $this->app->make(ExceptionHandler::class)
            ->reportable(function (\Throwable $e) use ($capture) {
                if (! $this->app->runningInConsole()) {
                    $capture->capture($e, 'request');
                }
            });

        $this->app->make('events')->listen(
            ExceptionReported::class,
            fn ($event) => $capture->capture($event->exception, 'reported'),
        );
    }

    protected function bootTracing(): void
    {
        if (! config('vigilance.tracing.enabled', false)) {
            return;
        }

        /** @var Tracer $tracer */
        $tracer = $this->app->make(Tracer::class);
        $events = $this->app->make('events');

        // --- Root traces ---------------------------------------------------
        if (config('vigilance.tracing.capture.requests', true)) {
            $app = $this->app;
            $prepend = fn ($kernel) => $kernel->prependMiddleware(TraceRequests::class);
            $app->afterResolving(\Illuminate\Contracts\Http\Kernel::class, $prepend);
            if ($app->resolved(\Illuminate\Contracts\Http\Kernel::class)) {
                $prepend($app->make(\Illuminate\Contracts\Http\Kernel::class));
            }
        }

        if (config('vigilance.tracing.capture.jobs', true)) {
            $events->listen(JobProcessing::class, fn ($e) => $tracer->rescue(
                fn () => $tracer->start('job', $e->job->resolveName(), null, [
                    'connection' => $e->connectionName,
                    'queue' => $e->job->getQueue(),
                ])
            ));
            $events->listen(JobProcessed::class, fn () => $tracer->finish('ok'));
            $events->listen(JobFailed::class, fn () => $tracer->finish('error'));
        }

        if (config('vigilance.tracing.capture.commands', false)) {
            $events->listen(CommandStarting::class, function ($e) use ($tracer) {
                $name = (string) ($e->command ?: 'command');
                if (! Vigilance::ignoresCommand($name)) {
                    $tracer->rescue(fn () => $tracer->start('command', $name));
                }
            });
            $events->listen(CommandFinished::class, fn ($e) => $tracer->finish(
                ((int) ($e->exitCode ?? 0)) !== 0 ? 'error' : 'ok'
            ));
        }

        // --- Child spans ---------------------------------------------------
        if (config('vigilance.tracing.spans.queries', true)) {
            $events->listen(QueryExecuted::class, function ($e) use ($tracer) {
                if ($tracer->sampling()) {
                    $tracer->rescue(fn () => $tracer->spanForDuration('query', $e->sql, (float) $e->time, [
                        'connection' => $e->connectionName,
                    ]));
                }
            });
        }

        if (config('vigilance.tracing.spans.cache', true)) {
            $events->listen([
                CacheHit::class,
                CacheMissed::class,
            ], function ($e) use ($tracer) {
                if (! $tracer->sampling()) {
                    return;
                }
                $hit = $e instanceof CacheHit;
                $now = microtime(true);
                $tracer->rescue(fn () => $tracer->span('cache', ($hit ? 'hit ' : 'miss ').$e->key, $now, $now, [
                    'hit' => $hit,
                    'store' => $e->storeName,
                ]));
            });
        }

        if (config('vigilance.tracing.spans.http', true)) {
            $app = $this->app;
            $install = fn ($factory) => $factory->globalMiddleware($this->traceHttpMiddleware($tracer));
            $app->afterResolving(Factory::class, $install);
            if ($app->resolved(Factory::class)) {
                $install($app->make(Factory::class));
            }
        }

        if (config('vigilance.tracing.spans.redis', true)) {
            $events->listen(CommandExecuted::class, function ($e) use ($tracer) {
                if ($tracer->sampling()) {
                    $tracer->rescue(fn () => $tracer->spanForDuration('redis', strtoupper((string) $e->command), (float) $e->time, [
                        'connection' => $e->connectionName,
                    ]));
                }
            });
        }

        if (config('vigilance.tracing.spans.mail', true)) {
            $events->listen(MessageSent::class, function () use ($tracer) {
                if ($tracer->sampling()) {
                    $now = microtime(true);
                    $tracer->rescue(fn () => $tracer->span('mail', 'mail sent', $now, $now));
                }
            });
        }

        if (config('vigilance.tracing.spans.notifications', true)) {
            $events->listen(NotificationSent::class, function ($e) use ($tracer) {
                if ($tracer->sampling()) {
                    $now = microtime(true);
                    $tracer->rescue(fn () => $tracer->span('notification', 'notify '.(string) $e->channel, $now, $now, [
                        'channel' => $e->channel,
                    ]));
                }
            });
        }

        // Octane: rebind container + drop any in-flight trace per request.
        if (class_exists('Laravel\Octane\Events\RequestReceived')) {
            foreach ([
                'Laravel\Octane\Events\RequestReceived',
                'Laravel\Octane\Events\TaskReceived',
                'Laravel\Octane\Events\TickReceived',
            ] as $event) {
                $events->listen($event, function ($e) use ($tracer) {
                    $tracer->setContainer($e->sandbox);
                    $tracer->flush();
                });
            }
        }
    }

    /**
     * Guzzle global middleware that records an outgoing-HTTP span when a trace
     * is active.
     */
    protected function traceHttpMiddleware(Tracer $tracer): callable
    {
        return fn (callable $handler) => function ($request, array $options) use ($handler, $tracer) {
            $start = microtime(true);

            $record = function (?int $status) use ($request, $start, $tracer) {
                if ($tracer->sampling()) {
                    $tracer->rescue(fn () => $tracer->span(
                        'http',
                        $request->getMethod().' '.(string) $request->getUri(),
                        $start,
                        microtime(true),
                        $status !== null ? ['status' => $status] : [],
                    ));
                }
            };

            return $handler($request, $options)->then(
                function ($response) use ($record) {
                    $record($response->getStatusCode());

                    return $response;
                },
                function ($reason) use ($record) {
                    $record(null);

                    return new RejectedPromise($reason);
                },
            );
        };
    }

    protected function registerAbout(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Vigilance', fn () => [
            'Version' => Vigilance::$version,
            'Enabled' => config('vigilance.enabled') ? '<fg=green;options=bold>ENABLED</>' : 'OFF',
            'Dashboard' => '/'.config('vigilance.path', 'vigilance'),
            'Storage connection' => config('vigilance.storage.connection') ?: config('database.default'),
            'Sample rate' => (string) config('vigilance.capture.sample_rate', 1.0),
            'Manual control' => config('vigilance.control.enabled') ? 'enabled' : 'disabled',
            'APM' => config('vigilance.apm.enabled') ? '<fg=green;options=bold>enabled</>' : 'off',
            'Tracing' => config('vigilance.tracing.enabled') ? '<fg=green;options=bold>enabled</>' : 'off',
        ]);
    }

    protected function registerStateReset(): void
    {
        if (! class_exists('Laravel\Octane\Events\RequestReceived')) {
            return;
        }

        $events = $this->app['events'];

        foreach ([
            'Laravel\Octane\Events\RequestReceived',
            'Laravel\Octane\Events\TaskReceived',
            'Laravel\Octane\Events\TickReceived',
        ] as $event) {
            $events->listen($event, function () {
                Vigilance::flushState();
                ControlGate::flush();
                $this->app->make(Recorder::class)->flushStack();
            });
        }
    }

    protected function registerCapture(): void
    {
        $recorder = $this->app->make(Recorder::class);

        if (config('vigilance.capture.jobs', true)) {
            (new JobCapture($recorder))->register();
        }

        if (config('vigilance.capture.commands', true)) {
            (new CommandCapture($recorder))->register();
        }

        if (config('vigilance.capture.schedule', true)) {
            (new ScheduleCapture($recorder))->register();
        }
    }

    protected function registerAssets(): void
    {
        // The bundled stylesheet is served unauthenticated (it carries no data)
        // so it always loads, independent of the dashboard authorization gate.
        Route::group([
            'domain' => config('vigilance.domain'),
            'prefix' => config('vigilance.path', 'vigilance'),
            'as' => 'vigilance.assets.',
        ], function () {
            Route::get('vigilance.css', [AssetController::class, 'css'])->name('css');
        });
    }

    protected function registerRoutes(): void
    {
        $routes = __DIR__.'/../routes/web.php';

        if (! file_exists($routes)) {
            return;
        }

        Route::group([
            'domain' => config('vigilance.domain'),
            'prefix' => config('vigilance.path', 'vigilance'),
            'middleware' => array_merge(
                (array) config('vigilance.middleware', ['web']),
                [Authorize::class],
            ),
            'as' => 'vigilance.',
        ], function () use ($routes) {
            require $routes;
        });
    }

    protected function registerLivewire(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        foreach ($this->livewireComponents() as $alias => $class) {
            if (class_exists($class)) {
                Livewire::component($alias, $class);
            }
        }
    }

    /** @return array<string, class-string> */
    protected function livewireComponents(): array
    {
        return [
            'vigilance.overview' => Overview::class,
            'vigilance.apm' => ApmPage::class,
            'vigilance.apm-card' => ApmCard::class,
            'vigilance.traces' => Traces::class,
            'vigilance.trace-detail' => TraceDetail::class,
            'vigilance.runs' => Runs::class,
            'vigilance.routes' => Routes::class,
            'vigilance.run-detail' => RunDetail::class,
            'vigilance.failures' => Failures::class,
            'vigilance.issue-detail' => IssueDetail::class,
            'vigilance.tags' => Tags::class,
            'vigilance.dispatcher' => Dispatcher::class,
            'vigilance.command-runner' => CommandRunner::class,
            'vigilance.schedule' => Schedule::class,
            'vigilance.workload' => Workload::class,
            'vigilance.workers' => Workers::class,
            'vigilance.pending' => Pending::class,
            'vigilance.batches' => Batches::class,
            'vigilance.metrics' => Metrics::class,
            'vigilance.metric-detail' => MetricDetail::class,
        ];
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $commands = array_filter([
            InstallCommand::class,
            DoctorCommand::class,
            PruneCommand::class,
            SnapshotCommand::class,
            ScheduleSyncCommand::class,
            SuperviseCommand::class,
            PauseCommand::class,
            ContinueCommand::class,
            RestartCommand::class,
            TerminateCommand::class,
            StatusCommand::class,
            CheckCommand::class,
            WorkCommand::class,
            DeployCommand::class,
            HealthCommand::class,
        ], 'class_exists');

        $this->commands($commands);
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/vigilance.php' => config_path('vigilance.php'),
        ], 'vigilance-config');

        $this->publishes([
            __DIR__.'/../stubs/VigilanceServiceProvider.stub' => app_path('Providers/VigilanceServiceProvider.php'),
        ], 'vigilance-provider');

        // The APM dashboard layout (and any view) can be published and edited to
        // rearrange / resize / drop / add cards.
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/vigilance'),
        ], 'vigilance-views');
    }
}
