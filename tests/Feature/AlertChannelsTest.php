<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\AlertManager;
use Vigilance\Notifications\Contracts\AlertRule;

uses(RefreshDatabase::class);

class AlwaysAlertRule implements AlertRule
{
    public function evaluate(): iterable
    {
        yield new Alert('chan-test', 'Test alert', 'Something happened', 'critical');
    }
}

it('dispatches alerts to discord, teams and generic webhooks', function () {
    config()->set('vigilance.notifications.discord', 'https://discord.test/wh');
    config()->set('vigilance.notifications.teams', 'https://teams.test/wh');
    config()->set('vigilance.notifications.webhooks', 'https://hook.test/a');
    config()->set('vigilance.alerts.custom', [AlwaysAlertRule::class]);

    Http::fake();

    $sent = app(AlertManager::class)->check();

    expect($sent)->toBeGreaterThanOrEqual(1);

    Http::assertSent(fn ($r) => $r->url() === 'https://discord.test/wh' && $r['content'] !== null);
    Http::assertSent(fn ($r) => $r->url() === 'https://teams.test/wh' && $r['@type'] === 'MessageCard');
    Http::assertSent(fn ($r) => $r->url() === 'https://hook.test/a' && $r['level'] === 'critical');
});
