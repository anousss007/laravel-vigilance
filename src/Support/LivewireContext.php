<?php

namespace Vigilance\Support;

use Illuminate\Http\Request;

/**
 * Recovers the Livewire component behind a "livewire/update" request.
 *
 * The URL of a Livewire update is opaque — "/livewire/update" (or a randomised
 * "/livewire-xxxx/update") says nothing about which component rendered. But the
 * component that actually broke — a Filament RelationManager, say — is right there
 * in the request body: components[0].snapshot.memo.name. That name is THE useful
 * context for an error raised during a Livewire render, so we surface it as the
 * issue's culprit (Sentry shows the same as "livewire?component=…").
 */
class LivewireContext
{
    /**
     * The component name for the current Livewire update request, or null when the
     * request isn't a Livewire update (or the payload can't be read).
     */
    public static function component(?Request $request = null): ?string
    {
        $request ??= self::request();

        if (! $request instanceof Request || ! self::isUpdate($request)) {
            return null;
        }

        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return null;
        }

        // Livewire posts one entry per component on the page; the first is the one
        // whose action triggered the round trip. Snapshots may arrive as a JSON
        // string (Livewire v3) or already decoded, so handle both.
        foreach ($components as $component) {
            $snapshot = $component['snapshot'] ?? null;

            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true);
            }

            $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * A Sentry-style culprit label for the component, or null — e.g.
     * "livewire?component=app.filament…photos-relation-manager".
     */
    public static function culprit(?Request $request = null): ?string
    {
        $component = self::component($request);

        return $component !== null ? 'livewire?component='.$component : null;
    }

    protected static function isUpdate(Request $request): bool
    {
        // Matches the default "livewire/update" and the obfuscated
        // "livewire-<hash>/update" route Livewire can be configured to use.
        return $request->isMethod('POST')
            && (bool) preg_match('#(^|/)livewire(-[^/]+)?/update$#', '/'.ltrim($request->path(), '/'));
    }

    protected static function request(): ?Request
    {
        try {
            return request();
        } catch (\Throwable) {
            return null;
        }
    }
}
