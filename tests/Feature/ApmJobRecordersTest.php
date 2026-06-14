<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Recorders\Queues;
use Vigilance\Apm\Recorders\SlowJobs;
use Vigilance\Apm\Recorders\UserJobs;
use Vigilance\Tests\Fixtures\FakeJob;

uses(RefreshDatabase::class);

function apmTotal(string $type): float
{
    return app(Storage::class)->aggregateTotal($type, 'count', CarbonInterval::hours(1));
}

it('records queue throughput per connection:queue', function () {
    $job = new FakeJob;
    $recorder = app(Queues::class);

    $recorder->record(new JobProcessing('database', $job));
    $recorder->record(new JobProcessed('database', $job));
    $recorder->record(new JobFailed('database', $job, new RuntimeException('x')));

    app(Apm::class)->ingest();

    expect(apmTotal('queue_processing'))->toBe(1.0)
        ->and(apmTotal('queue_processed'))->toBe(1.0)
        ->and(apmTotal('queue_failed'))->toBe(1.0);

    // onlyBuckets metrics carry the key in the aggregates table (read via graph).
    $graph = app(Storage::class)->graph(['queue_processed'], 'count', CarbonInterval::hours(1));
    expect($graph->keys()->all())->toContain('database:default');
});

it('ignores sync-connection jobs', function () {
    $recorder = app(Queues::class);
    $recorder->record(new JobProcessed('sync', new FakeJob));

    app(Apm::class)->ingest();

    expect(apmTotal('queue_processed'))->toBe(0.0);
});

it('records a slow job over the threshold keyed by name', function () {
    config()->set('vigilance.apm.recorders.'.SlowJobs::class.'.threshold', 0);

    $job = new FakeJob('App\\Jobs\\Heavy');
    $recorder = app(SlowJobs::class);

    $recorder->record(new JobProcessing('database', $job));
    $recorder->record(new JobProcessed('database', $job));

    app(Apm::class)->ingest();

    expect(apmTotal('slow_job'))->toBe(1.0);

    $rows = app(Storage::class)->aggregate('slow_job', ['max', 'count'], CarbonInterval::hours(1));
    expect($rows->first()->key)->toBe('App\\Jobs\\Heavy');
});

it('records jobs per dispatching user', function () {
    app(Apm::class)->resolveUserUsing(fn () => ['id' => 5, 'name' => 'Sam', 'extra' => 'sam@x.test']);

    $recorder = app(UserJobs::class);
    $recorder->record(new JobQueued('database', 'default', 'job-id', new FakeJob, fn () => ['uuid' => 'u1'], 0));

    app(Apm::class)->ingest();

    expect(apmTotal('user_job'))->toBe(1.0);

    $user = app(Storage::class)->values('user')->get('5');
    expect(json_decode($user->value, true)['name'])->toBe('Sam');
});
