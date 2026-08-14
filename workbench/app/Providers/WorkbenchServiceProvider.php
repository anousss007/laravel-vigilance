<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Vigilance\Vigilance;
use Workbench\App\Jobs\BookingDraftSweepJob;
use Workbench\App\Jobs\ExpireReservationJob;
use Workbench\App\Jobs\SendConfirmationNudgesJob;

/**
 * Wires the throwaway app the visual harness runs against: it opens the
 * dashboard to any visitor (there is no login in the workbench) and stops
 * Vigilance from recording the screenshot run itself, which would otherwise
 * append meaningless rows on top of the seeded demo data mid-capture.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Pin the database in code rather than through env: `testbench serve`
        // runs the app behind Laravel's dev server, which forwards only a fixed
        // allow-list of environment variables, and phpunit.xml's DB_CONNECTION
        // would otherwise point the served app at an empty in-memory database.
        $database = dirname(__DIR__, 2).'/database/database.sqlite';

        config([
            'database.default' => 'workbench',
            'database.connections.workbench' => [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        // Batch monitoring reads Laravel's own batch repository, which has its
        // own connection setting — point it at the same file or the Batches
        // page renders its "batching isn't set up" notice instead.
        config([
            'queue.batching.database' => 'workbench',
            // The database driver is the only one whose backlog can be browsed,
            // so the harness runs on it — otherwise Pending only ever shows the
            // "not browsable for this driver" note.
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'workbench',
        ]);

        // SLOs and the log explorer are opt-in, and their nav entries only
        // appear once configured — the harness has to photograph them too.
        config([
            'vigilance.slos' => [
                'availability' => ['name' => 'API availability', 'sli' => 'success_rate', 'target' => 99.9, 'window_days' => 7],
                'page-speed' => ['name' => 'Page speed', 'sli' => 'latency', 'target' => 95.0, 'window_days' => 7],
            ],
            'vigilance.logs.enabled' => true,
            'vigilance.rum.enabled' => true,

            // Manual control is off by default in a real app, which makes the
            // Dispatch and Commands pages render a one-line "disabled" notice.
            // Turn it on so the harness photographs the actual forms.
            'vigilance.control.enabled' => true,
            'vigilance.control.jobs.mode' => 'list',
            'vigilance.control.jobs.allow' => [
                SendConfirmationNudgesJob::class,
                ExpireReservationJob::class,
                BookingDraftSweepJob::class,
            ],
            // Supervisors are "alive" only while their heartbeat is inside this
            // window. The seeded fleet would otherwise go stale part-way
            // through a sweep and the Workers page would photograph empty.
            'vigilance.supervision.heartbeat_expire' => 86400,

            'vigilance.control.commands.mode' => 'list',
            'vigilance.control.commands.allow' => ['cache:clear', 'queue:prune-batches', 'vigilance:prune'],
        ]);
    }

    public function boot(): void
    {
        Vigilance::auth(fn () => true);

        if (env('VIGILANCE_DEMO_FREEZE', true)) {
            Vigilance::recordOff();
        }
    }
}
