<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Vigilance\Apm\Apm;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Notifications\Rules\ErrorRateRule;
use Vigilance\Notifications\Rules\ExceptionSpikeRule;
use Vigilance\Notifications\Rules\SlowRequestRateRule;

uses(RefreshDatabase::class);

function failedRun(string $name, ?string $exceptionClass): void
{
    Run::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => $name,
        'status' => RunStatus::Failed->value,
        'exception_class' => $exceptionClass,
    ]);
}

it('error-rate alert names the top failing jobs and their exception', function () {
    config()->set('vigilance.alerts.rules.error_rate', ['enabled' => true, 'min_runs' => 1, 'percent' => 1]);

    failedRun('App\\Jobs\\OrderJob', 'RuntimeException');
    failedRun('App\\Jobs\\OrderJob', 'RuntimeException');
    failedRun('App\\Jobs\\EmailJob', 'Symfony\\Component\\Mailer\\Exception\\TransportException');

    $msg = iterator_to_array(app(ErrorRateRule::class)->evaluate())[0]->message;

    expect($msg)->toContain('App\\Jobs\\OrderJob (RuntimeException) ×2')
        ->toContain('App\\Jobs\\EmailJob (TransportException) ×1');
});

it('exception-spike alert names the top exception class and code location', function () {
    config()->set('vigilance.alerts.rules.exception_spike', ['enabled' => true, 'count' => 1]);

    $apm = app(Apm::class);
    foreach ([['RuntimeException', 'app/Services/Billing.php:88'], ['RuntimeException', 'app/Services/Billing.php:88'], ['TypeError', 'app/Http/X.php:12']] as [$class, $loc]) {
        $apm->record('exception', (string) json_encode(['class' => $class, 'location' => $loc]), time())->max()->count();
    }
    $apm->ingest();

    $msg = iterator_to_array(app(ExceptionSpikeRule::class)->evaluate())[0]->message;

    expect($msg)->toContain('RuntimeException @ app/Services/Billing.php:88 (2×)')
        ->toContain('TypeError @ app/Http/X.php:12 (1×)');
});

it('slow-request-rate alert names the slowest routes', function () {
    config()->set('vigilance.alerts.rules.slow_request_rate', ['enabled' => true, 'count' => 1]);

    $apm = app(Apm::class);
    $apm->record('slow_request', (string) json_encode(['GET', '/orders']), 2100)->max()->count();
    $apm->record('slow_request', (string) json_encode(['GET', '/orders']), 1800)->max()->count();
    $apm->record('slow_request', (string) json_encode(['POST', '/checkout']), 1500)->max()->count();
    $apm->ingest();

    $msg = iterator_to_array(app(SlowRequestRateRule::class)->evaluate())[0]->message;

    expect($msg)->toContain('GET /orders')
        ->toContain('max 2100ms');
});
