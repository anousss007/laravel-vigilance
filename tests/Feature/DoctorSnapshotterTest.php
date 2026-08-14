<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;

uses(RefreshDatabase::class);

function recordSnapshotHeartbeat(int $agoMinutes): void
{
    app(Apm::class)->set('vigilance', 'snapshot', (string) json_encode([
        'ran_at' => time() - ($agoMinutes * 60),
    ]));
    app(Apm::class)->ingest();
}

it('reports a healthy snapshotter', function () {
    recordSnapshotHeartbeat(2);

    $this->artisan('vigilance:doctor')
        ->expectsOutputToContain('Snapshotter')
        ->assertSuccessful();
});

it('fails when the snapshotter has gone silent', function () {
    // This is the whole point of the check: MonitoringHealthRule runs *from*
    // the snapshotter, so when the snapshotter dies the rule dies with it and
    // no alert can ever report the silence. A non-zero exit here is what lets
    // an external uptime check notice.
    recordSnapshotHeartbeat(180);

    $this->artisan('vigilance:doctor')->assertFailed();
});

it('warns rather than fails when it has never run', function () {
    // A fresh install has simply not scheduled it yet — that is a setup note,
    // not an outage.
    $this->artisan('vigilance:doctor')
        ->expectsOutputToContain('never ran')
        ->assertSuccessful();
});

it('says nothing is expected when metrics are disabled', function () {
    config()->set('vigilance.metrics.enabled', false);

    $this->artisan('vigilance:doctor')
        ->expectsOutputToContain('nothing to schedule')
        ->assertSuccessful();
});

it('honours the configured staleness window', function () {
    config()->set('vigilance.metrics.snapshot_stale_after', 600);

    recordSnapshotHeartbeat(180);

    $this->artisan('vigilance:doctor')->assertSuccessful();
});

it('closes the loop end to end: snapshot writes the beat doctor reads', function () {
    $this->artisan('vigilance:snapshot')->assertSuccessful();

    // Real runs flush from the console kernel's terminate hook, which
    // $this->artisan() does not call.
    app(Apm::class)->ingest();

    $this->artisan('vigilance:doctor')
        ->expectsOutputToContain('last ran 0 minute(s) ago')
        ->assertSuccessful();
});
