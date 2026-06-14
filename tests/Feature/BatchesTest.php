<?php

use Illuminate\Bus\BatchRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Vigilance\Http\Livewire\Batches;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Vigilance::auth(fn () => true);
    // The batch repository reads its own configured connection — point it at the
    // test database and rebuild the singleton.
    config()->set('queue.batching.database', 'testing');
    app()->forgetInstance(BatchRepository::class);
});

function createJobBatchesTable(): void
{
    Schema::create('job_batches', function ($t) {
        $t->string('id')->primary();
        $t->string('name');
        $t->integer('total_jobs');
        $t->integer('pending_jobs');
        $t->integer('failed_jobs');
        $t->longText('failed_job_ids');
        $t->mediumText('options')->nullable();
        $t->integer('cancelled_at')->nullable();
        $t->integer('created_at');
        $t->integer('finished_at')->nullable();
    });
}

it('returns 200 on the batches route', function () {
    $this->get(route('vigilance.batches'))->assertOk();
});

it('shows a not-supported notice when batching is not set up', function () {
    Livewire::test(Batches::class)->assertOk()->assertSee('Job batching');
});

it('lists batches with progress and a failed badge', function () {
    createJobBatchesTable();

    DB::table('job_batches')->insert([
        'id' => 'batch-1',
        'name' => 'Import users',
        'total_jobs' => 10,
        'pending_jobs' => 4,
        'failed_jobs' => 1,
        'failed_job_ids' => '[]',
        'options' => serialize([]),
        'cancelled_at' => null,
        'created_at' => time(),
        'finished_at' => null,
    ]);

    expect(app(BatchRepository::class)->get(50, null))->toHaveCount(1);

    Livewire::test(Batches::class)
        ->assertOk()
        ->assertSee('Import users')
        ->assertSee('processing')
        ->assertSee('Retry failed')
        ->assertSee('Cancel');
});

it('cancels and retries a batch without error', function () {
    createJobBatchesTable();

    DB::table('job_batches')->insert([
        'id' => 'batch-2', 'name' => 'Resize images', 'total_jobs' => 5, 'pending_jobs' => 5,
        'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => serialize([]),
        'cancelled_at' => null, 'created_at' => time(), 'finished_at' => null,
    ]);

    Livewire::test(Batches::class)->call('cancel', 'batch-2');

    expect(app(BatchRepository::class)->find('batch-2')->cancelled())->toBeTrue();
});
