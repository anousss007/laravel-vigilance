<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Http\Livewire\Failures;
use Vigilance\Http\Livewire\IssueDetail;
use Vigilance\Models\FailureGroup;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(fn () => Vigilance::auth(fn () => true));

function makeFailureGroup(): FailureGroup
{
    return FailureGroup::query()->create([
        'signature' => str_repeat('a', 64),
        'name' => 'App\\Jobs\\Demo',
        'exception_class' => 'RuntimeException',
        'message' => 'boom',
        'occurrences' => 3,
        'first_seen_at' => now()->subHour(),
        'last_seen_at' => now(),
    ]);
}

it('acknowledges and prioritises a failure group', function () {
    Vigilance::auth(fn () => true);
    Vigilance::resolveUserUsing(fn () => 'ops@team.test');

    $group = makeFailureGroup();

    Livewire::test(Failures::class)
        ->call('acknowledge', $group->id)
        ->call('setPriority', $group->id, 'high');

    $group->refresh();

    expect($group->status())->toBe('acknowledged')
        ->and($group->acknowledged_at)->not->toBeNull()
        ->and($group->assignee)->toBe('ops@team.test')
        ->and($group->priority)->toBe('high');
});

it('clamps an invalid priority to normal', function () {
    $group = makeFailureGroup();

    Livewire::test(Failures::class)->call('setPriority', $group->id, 'bogus');

    expect($group->refresh()->priority)->toBe('normal');
});

it('mutes and unmutes an issue', function () {
    $group = makeFailureGroup();

    Livewire::test(Failures::class)->call('mute', $group->id, 24);

    expect($group->refresh()->isMuted())->toBeTrue()
        ->and($group->status())->toBe('muted');

    Livewire::test(Failures::class)->call('unmute', $group->id);

    expect($group->refresh()->isMuted())->toBeFalse();
});

it('filters issues by source', function () {
    FailureGroup::query()->create(['signature' => str_repeat('w', 64), 'source' => 'request', 'exception_class' => 'E', 'occurrences' => 1, 'last_seen_at' => now()]);
    FailureGroup::query()->create(['signature' => str_repeat('q', 64), 'source' => 'job', 'exception_class' => 'E', 'occurrences' => 1, 'last_seen_at' => now()]);

    Livewire::test(Failures::class)
        ->call('setSource', 'request')
        ->assertViewHas('groups', fn ($groups) => $groups->count() === 1 && $groups->first()->source === 'request');
});

it('renders and resolves an issue from the detail page', function () {
    $group = makeFailureGroup();
    $group->update(['source' => 'request', 'sample' => "RuntimeException: boom\n#0 /app/foo.php(10)"]);

    Livewire::test(IssueDetail::class, ['group' => $group])
        ->assertSee('RuntimeException')
        ->call('resolve');

    expect($group->refresh()->isResolved())->toBeTrue();

    Livewire::test(IssueDetail::class, ['group' => $group])->call('mute', 24);

    expect($group->refresh()->isMuted())->toBeTrue();
});

it('records uptime for configured urls', function () {
    config()->set('vigilance.uptime.urls', ['https://example.test/up']);
    Http::fake(['https://example.test/*' => Http::response('ok', 200)]);

    $this->artisan('vigilance:health')->assertSuccessful();

    $value = app(Storage::class)->values('uptime')->get('https://example.test/up');

    expect($value)->not->toBeNull()
        ->and(json_decode($value->value, true)['up'])->toBeTrue()
        ->and(json_decode($value->value, true)['status'])->toBe(200);
});

it('marks an unreachable url as down', function () {
    config()->set('vigilance.uptime.urls', ['https://down.test/up']);
    Http::fake(['https://down.test/*' => Http::response('err', 503)]);

    $this->artisan('vigilance:health')->assertSuccessful();

    $value = app(Storage::class)->values('uptime')->get('https://down.test/up');
    expect(json_decode($value->value, true)['up'])->toBeFalse();
});

it('prunes old audit entries', function () {
    DB::table('vigilance_audit')->insert([
        ['user' => 'a', 'action' => 'dispatch', 'created_at' => now()->subDays(40)],
        ['user' => 'b', 'action' => 'dispatch', 'created_at' => now()],
    ]);

    $this->artisan('vigilance:prune')->assertSuccessful();

    expect(DB::table('vigilance_audit')->count())->toBe(1);
});
