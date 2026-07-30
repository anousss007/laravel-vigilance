<?php

use Illuminate\Http\Request;
use Vigilance\Support\LivewireContext;

function livewireUpdate(array $components, string $path = '/livewire/update'): Request
{
    return Request::create($path, 'POST', ['components' => $components]);
}

it('recovers the component name from a livewire/update payload (string snapshot)', function () {
    // Livewire v3 posts the snapshot as a JSON string; the component name lives at
    // memo.name — the only thing that says which component broke.
    $request = livewireUpdate([[
        'snapshot' => json_encode([
            'memo' => ['id' => 'abc', 'name' => 'App\\Filament\\Resources\\CarResource\\RelationManagers\\PhotosRelationManager'],
        ]),
        'updates' => [],
        'calls' => [],
    ]]);

    expect(LivewireContext::component($request))
        ->toBe('App\\Filament\\Resources\\CarResource\\RelationManagers\\PhotosRelationManager')
        ->and(LivewireContext::culprit($request))
        ->toBe('livewire?component=App\\Filament\\Resources\\CarResource\\RelationManagers\\PhotosRelationManager');
});

it('also reads an already-decoded snapshot array', function () {
    $request = livewireUpdate([[
        'snapshot' => ['memo' => ['name' => 'App\\Livewire\\Dashboard']],
    ]]);

    expect(LivewireContext::component($request))->toBe('App\\Livewire\\Dashboard');
});

it('matches the obfuscated livewire-<hash>/update route', function () {
    $request = livewireUpdate(
        [['snapshot' => json_encode(['memo' => ['name' => 'App\\Livewire\\Widget']])]],
        '/livewire-af707229/update',
    );

    expect(LivewireContext::component($request))->toBe('App\\Livewire\\Widget');
});

it('returns null for non-livewire or malformed requests', function () {
    // GET, not an update.
    expect(LivewireContext::component(Request::create('/livewire/update', 'GET')))->toBeNull();

    // A different POST path.
    expect(LivewireContext::component(Request::create('/checkout', 'POST', ['components' => [['snapshot' => '{}']]])))->toBeNull();

    // Update path but no component name to recover.
    expect(LivewireContext::component(livewireUpdate([['snapshot' => '{"memo":{}}']])))->toBeNull();
    expect(LivewireContext::culprit(livewireUpdate([])))->toBeNull();
});
