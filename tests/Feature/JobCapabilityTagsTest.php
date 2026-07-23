<?php

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Vigilance\Capture\TagExtractor;

it('tags jobs with their queue capabilities', function () {
    $unique = new class implements ShouldBeUnique {};
    $encrypted = new class implements ShouldBeEncrypted {};
    $plain = new class {};

    expect(TagExtractor::for($unique))->toContain('unique')
        ->and(TagExtractor::for($encrypted))->toContain('encrypted')
        ->and(TagExtractor::for($plain))->not->toContain('unique')
        ->and(TagExtractor::for($plain))->not->toContain('encrypted');
});
