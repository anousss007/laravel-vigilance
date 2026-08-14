<?php

namespace Vigilance\Tests;

use Vigilance\Apm\Recorders\RequestProfile;

/**
 * Boots the app with the RequestProfile recorder enabled, so its query / model
 * listeners and terminate hook are wired at boot. The recorder is opt-in, so
 * without this the default boot registers nothing to assert against.
 */
class RequestProfileTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vigilance.apm.recorders.'.RequestProfile::class.'.enabled', true);
    }
}
