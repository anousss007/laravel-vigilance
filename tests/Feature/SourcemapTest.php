<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Vigilance\Apm\Apm;
use Vigilance\Capture\FailureGrouper;
use Vigilance\Http\Controllers\RumController;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\SourceMapRecord;
use Vigilance\Sourcemaps\SourceMap;
use Vigilance\Sourcemaps\SourceMapStore;

uses(RefreshDatabase::class);

// sources=[resources/js/app.js]; two segments: genCol 0 → src line 1, genCol 5 → src line 3.
const SAMPLE_MAP = '{"version":3,"sources":["resources/js/app.js"],"names":[],"mappings":"AAAA,KAEA"}';

it('decodes a source map and resolves original positions', function () {
    $map = SourceMap::fromJson(SAMPLE_MAP);

    expect($map)->not->toBeNull();

    $first = $map->originalPositionFor(1, 1);
    expect($first['source'])->toBe('resources/js/app.js')
        ->and($first['line'])->toBe(1)
        ->and($first['column'])->toBe(1);

    $second = $map->originalPositionFor(1, 6);
    expect($second['line'])->toBe(3)
        ->and($second['column'])->toBe(1);
});

it('symbolicates a minified stack against a stored map', function () {
    app(SourceMapStore::class)->put('v1', 'app.js', SAMPLE_MAP);

    $stack = "TypeError: boom\n    at https://app.test/build/app.js:1:6\n    at https://app.test/build/app.js:1:1";

    $result = app(SourceMapStore::class)->symbolicate('v1', $stack);

    expect($result)->not->toBeNull()
        ->and($result)->toContain('resources/js/app.js:3:1')
        ->and($result)->toContain('(https://app.test/build/app.js:1:6)');
});

it('returns null when no map matches the release', function () {
    app(SourceMapStore::class)->put('v1', 'app.js', SAMPLE_MAP);

    expect(app(SourceMapStore::class)->symbolicate('v2', 'at app.js:1:6'))->toBeNull();
});

it('uploads source maps from a directory via the command', function () {
    $dir = sys_get_temp_dir().'/vig-maps-'.substr(md5(SAMPLE_MAP), 0, 8);
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/app.js.map', SAMPLE_MAP);

    Artisan::call('vigilance:sourcemaps', ['path' => $dir, '--release' => 'v9']);

    $record = SourceMapRecord::query()->where('release', 'v9')->where('file', 'app.js')->first();

    expect($record)->not->toBeNull()
        ->and($record->content)->toContain('resources/js/app.js');

    @unlink($dir.'/app.js.map');
    @rmdir($dir);
});

it('symbolicates a browser error captured through the RUM endpoint', function () {
    config()->set('vigilance.rum.enabled', true);
    config()->set('vigilance.release', 'v1');

    app(SourceMapStore::class)->put('v1', 'app.js', SAMPLE_MAP);

    $payload = [
        'page' => '/checkout',
        'errors' => [['message' => 'TypeError: boom', 'stack' => 'at https://app.test/build/app.js:1:6']],
    ];
    $request = Request::create('/vigilance/rum', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));

    app(RumController::class)->store($request, app(Apm::class), app(FailureGrouper::class));

    $issue = FailureGroup::query()->where('source', 'browser')->firstOrFail();

    expect($issue->sample)->toContain('resources/js/app.js:3:1');
});
