<?php

namespace Vigilance\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the dashboard's pre-compiled, self-contained stylesheet. Bundling the
 * CSS (instead of a CDN) keeps the dashboard working offline and avoids any
 * external request or Content-Security-Policy headache in production.
 */
class AssetController
{
    public function css(): Response
    {
        $path = realpath(__DIR__.'/../../../resources/dist/vigilance.css');

        abort_if($path === false, 404, 'Vigilance assets have not been built.');

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
