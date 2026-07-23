<?php

use Vigilance\Support\CodeLocation;

it('never resolves to a Vigilance-internal or vendor frame', function () {
    // In the package's own test suite every frame lives under the package
    // directory or vendor, so caller() correctly resolves to no application
    // frame (null) rather than pointing at Vigilance's own internals — the exact
    // regression guard for the N+1 "caller" location. The positive path (a real
    // app frame → "relative/path.php:line") is covered end-to-end against a live
    // Laravel app.
    expect(CodeLocation::caller())->toBeNull();
});
