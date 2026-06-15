<?php

use Illuminate\Support\Facades\Route;
use Vigilance\Http\Livewire\Apm;
use Vigilance\Http\Livewire\Batches;
use Vigilance\Http\Livewire\CommandRunner;
use Vigilance\Http\Livewire\Dispatcher;
use Vigilance\Http\Livewire\Failures;
use Vigilance\Http\Livewire\IssueDetail;
use Vigilance\Http\Livewire\MetricDetail;
use Vigilance\Http\Livewire\Metrics;
use Vigilance\Http\Livewire\Overview;
use Vigilance\Http\Livewire\Pending;
use Vigilance\Http\Livewire\Routes;
use Vigilance\Http\Livewire\RunDetail;
use Vigilance\Http\Livewire\Runs;
use Vigilance\Http\Livewire\Schedule;
use Vigilance\Http\Livewire\Tags;
use Vigilance\Http\Livewire\TraceDetail;
use Vigilance\Http\Livewire\Traces;
use Vigilance\Http\Livewire\Vitals;
use Vigilance\Http\Livewire\Workers;
use Vigilance\Http\Livewire\Workload;

/*
|--------------------------------------------------------------------------
| Vigilance dashboard routes
|--------------------------------------------------------------------------
|
| These are registered by VigilanceServiceProvider inside a route group that
| supplies the URI prefix (config('vigilance.path')), the dashboard middleware
| stack (config('vigilance.middleware') + the Authorize middleware), and the
| "vigilance." route-name prefix. So we only register the relative routes here.
|
*/

Route::get('/', Overview::class)->name('overview');
Route::get('/apm', Apm::class)->name('apm');
Route::get('/traces', Traces::class)->name('traces');
Route::get('/traces/{trace}', TraceDetail::class)->name('traces.show');
Route::get('/runs', Runs::class)->name('runs');
Route::get('/runs/{run}', RunDetail::class)->name('runs.show');
Route::get('/issues', Failures::class)->name('issues');
Route::get('/issues/{group}', IssueDetail::class)->name('issues.show');
Route::get('/tags', Tags::class)->name('tags');
Route::get('/dispatch', Dispatcher::class)->name('dispatch');
Route::get('/commands', CommandRunner::class)->name('commands');
Route::get('/schedule', Schedule::class)->name('schedule');
Route::get('/workload', Workload::class)->name('workload');
Route::get('/workers', Workers::class)->name('workers');
Route::get('/pending', Pending::class)->name('pending');
Route::get('/batches', Batches::class)->name('batches');
Route::get('/routes', Routes::class)->name('routes');
Route::get('/vitals', Vitals::class)->name('vitals');
Route::get('/metrics', Metrics::class)->name('metrics');
Route::get('/metrics/view', MetricDetail::class)->name('metrics.show');
