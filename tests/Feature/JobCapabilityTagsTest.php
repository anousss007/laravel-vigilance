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

it('derives capability tags from a class name alone (for opaque encrypted jobs)', function () {
    // An encrypted job's command object cannot be reconstructed at capture time,
    // so tagging must work from the class name — the case that most needs it.
    $encrypted = new class implements ShouldBeEncrypted {};
    $unique = new class implements ShouldBeUnique {};

    expect(TagExtractor::forClass($encrypted::class))->toBe(['encrypted'])
        ->and(TagExtractor::forClass($unique::class))->toBe(['unique'])
        ->and(TagExtractor::forClass(stdClass::class))->toBe([])
        ->and(TagExtractor::forClass('Nonexistent\\Class'))->toBe([])
        ->and(TagExtractor::forClass(null))->toBe([]);
});
