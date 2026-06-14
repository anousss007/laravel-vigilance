<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Recorders\Concerns\Sampling;

/**
 * Counts requests per authenticated user (the request side of the Application
 * Usage card). Hooks the kernel terminate phase like SlowRequests, and stores a
 * latest-wins "user" display snapshot so the dashboard can render names without
 * a reverse lookup.
 */
class UserRequests extends Recorder
{
    use Sampling;

    public function register(Apm $apm): void
    {
        $app = $apm->container();

        $hook = fn (Kernel $kernel) => $kernel->whenRequestLifecycleIsLongerThan(-1, function ($startedAt, $request, $response) {
            $this->record($startedAt, $request, $response);
        });

        $app->afterResolving(Kernel::class, $hook);

        if ($app->resolved(Kernel::class)) {
            $hook($app->make(Kernel::class));
        }
    }

    public function record(\DateTimeInterface $startedAt, Request $request, Response $response): void
    {
        if (! $request->route() instanceof Route || ! $this->shouldSample()) {
            return;
        }

        $user = $this->apm->resolveUser();

        if ($user === null) {
            return;
        }

        $this->apm->record('user_request', (string) $user['id'], null, $startedAt->getTimestamp())->count();
        $this->apm->set('user', (string) $user['id'], (string) json_encode($user), $startedAt->getTimestamp());
    }
}
