<?php

namespace Vigilance\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Vigilance\Models\UserFeedback;
use Vigilance\Tracing\Tracer;
use Vigilance\Vigilance;

/**
 * Public (unauthenticated, throttled) endpoint for the user-feedback widget:
 * a user reports a problem even when nothing crashed, and it's stored tied to
 * the trace they were on so the complaint links to the technical telemetry.
 * Opt-in (vigilance.feedback.enabled). Strictly validated and capped so an open
 * endpoint can't be used to flood storage.
 */
class FeedbackController
{
    public function store(Request $request, Tracer $tracer): Response
    {
        abort_unless((bool) config('vigilance.feedback.enabled', false), 404);

        $message = trim((string) $request->input('message', ''));

        if ($message === '') {
            return response()->noContent(); // nothing to record; never error the widget
        }

        try {
            UserFeedback::query()->create([
                'message' => Str::limit($message, (int) config('vigilance.feedback.max_length', 2000), ''),
                'email' => $this->clean($request->input('email'), 200),
                'name' => $this->clean($request->input('name'), 120),
                'url' => $this->clean($request->input('url') ?? $request->headers->get('referer'), 500),
                'trace_id' => $this->clean($request->input('trace_id') ?? $tracer->currentTraceId(), 36),
                'user' => Vigilance::currentUser($request),
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable) {
            // Capturing feedback must never surface an error to the end user.
        }

        return response()->noContent();
    }

    protected function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), $max, '');
    }
}
