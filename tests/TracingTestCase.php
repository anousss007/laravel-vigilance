<?php

namespace Vigilance\Tests;

/**
 * Boots the app with tracing enabled and sample_rate = 1, so the instrumentation
 * (middleware, query/cache/job listeners) is wired at boot and every trace is
 * kept for assertions.
 */
class TracingTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vigilance.tracing.enabled', true);
        $app['config']->set('vigilance.tracing.sample_rate', 1);
        $app['config']->set('vigilance.tracing.capture.commands', true);
    }
}
