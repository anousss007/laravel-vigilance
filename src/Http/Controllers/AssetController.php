<?php

namespace Vigilance\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the dashboard's pre-compiled, self-contained assets. Bundling them
 * (instead of a CDN) keeps the dashboard working offline and avoids any
 * external request or Content-Security-Policy headache in production — and is
 * why a consuming app needs no npm, Vite or Tailwind of its own.
 */
class AssetController
{
    public function css(): Response
    {
        return $this->serve('vigilance.css', 'text/css');
    }

    /**
     * The Alpine plugins, components and directives the vendored UI kit needs.
     * Deliberately does NOT contain Alpine itself — Livewire ships that, and a
     * second copy would fight it for the same DOM.
     */
    public function js(): Response
    {
        return $this->serve('vigilance.js', 'text/javascript');
    }

    protected function serve(string $file, string $contentType): Response
    {
        $path = realpath(__DIR__.'/../../../resources/dist/'.$file);

        abort_if($path === false, 404, 'Vigilance assets have not been built.');

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => $contentType.'; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
