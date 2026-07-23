<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vigilance\Http\Controllers\FeedbackController;
use Vigilance\Models\UserFeedback;
use Vigilance\Tracing\Tracer;

uses(RefreshDatabase::class);

function callFeedback(array $payload): void
{
    $request = Request::create('/vigilance/feedback', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));

    app(FeedbackController::class)->store($request, app(Tracer::class));
}

it('stores user feedback when enabled', function () {
    config()->set('vigilance.feedback.enabled', true);

    callFeedback(['message' => 'The export is slow', 'email' => 'user@example.com', 'url' => '/reports', 'trace_id' => 'abc123']);

    $feedback = UserFeedback::query()->first();

    expect($feedback)->not->toBeNull()
        ->and($feedback->message)->toBe('The export is slow')
        ->and($feedback->email)->toBe('user@example.com')
        ->and($feedback->url)->toBe('/reports')
        ->and($feedback->trace_id)->toBe('abc123');
});

it('ignores empty messages', function () {
    config()->set('vigilance.feedback.enabled', true);

    callFeedback(['message' => '   ']);

    expect(UserFeedback::query()->count())->toBe(0);
});

it('is a 404 when disabled', function () {
    config()->set('vigilance.feedback.enabled', false);

    expect(fn () => callFeedback(['message' => 'hi']))->toThrow(NotFoundHttpException::class);
    expect(UserFeedback::query()->count())->toBe(0);
});
