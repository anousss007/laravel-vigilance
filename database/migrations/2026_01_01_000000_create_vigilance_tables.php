<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function connection(): ?string
    {
        return config('vigilance.storage.connection') ?: config('database.default');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection());

        $schema->create('vigilance_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uuid', 36)->index();
            $table->string('type', 16)->index();
            $table->string('name')->nullable()->index();
            $table->string('display_name')->nullable();
            $table->string('status', 16)->index();

            $table->string('connection_name')->nullable();
            $table->string('queue')->nullable()->index();
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedBigInteger('retry_of')->nullable()->index();

            $table->longText('parameters')->nullable();
            $table->longText('payload_raw')->nullable();
            $table->json('tags')->nullable();
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();

            $table->string('exception_class')->nullable()->index();
            $table->text('exception_message')->nullable();
            $table->longText('exception')->nullable();
            $table->unsignedBigInteger('failure_group_id')->nullable()->index();

            $table->string('batch_id', 36)->nullable()->index();
            $table->string('via', 16)->nullable();
            $table->string('caused_by')->nullable();

            $table->unsignedBigInteger('memory_peak')->nullable();
            $table->unsignedBigInteger('cpu_time_ms')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('wait_ms')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable()->index();

            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        $schema->create('vigilance_run_tags', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('run_id')->index();
            $table->string('tag')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['tag', 'created_at']);
        });

        $schema->create('vigilance_failure_groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('signature', 64)->unique();
            $table->string('type', 16)->nullable();
            $table->string('name')->nullable();
            $table->string('exception_class')->nullable();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->string('priority', 16)->nullable();
            $table->string('assignee')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        $schema->create('vigilance_metric_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('scope_type', 16);
            $table->string('scope');
            $table->unsignedBigInteger('throughput')->default(0);
            $table->unsignedBigInteger('failures')->default(0);
            $table->unsignedBigInteger('runtime_avg_ms')->default(0);
            $table->unsignedBigInteger('wait_avg_ms')->nullable();
            $table->timestamp('measured_at')->index();

            $table->index(['scope_type', 'scope', 'measured_at']);
        });

        $schema->create('vigilance_scheduled_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->string('type', 16)->nullable();
            $table->string('cron_expression')->nullable();
            $table->string('timezone')->nullable();
            $table->unsignedInteger('grace_time_minutes')->default(5);
            $table->boolean('monitored')->default(true);
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('last_skipped_at')->nullable();
            $table->unsignedBigInteger('last_duration_ms')->nullable();
            $table->timestamps();
        });

        $schema->create('vigilance_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('user')->nullable()->index();
            $table->string('action', 32)->index();
            $table->string('subject')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        $schema->create('vigilance_supervisors', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->string('master')->nullable()->index();
            $table->string('host')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->string('status', 16)->default('running');
            $table->string('connection')->nullable();
            $table->text('queues')->nullable();
            $table->string('balance', 16)->nullable();
            $table->unsignedInteger('processes')->default(0);
            $table->json('pools')->nullable();
            $table->json('options')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamps();
        });

        $schema->create('vigilance_monitored_tags', function (Blueprint $table) {
            $table->string('tag')->primary();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('vigilance_workers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('supervisor')->index();
            $table->string('host')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->string('connection')->nullable();
            $table->string('queue')->nullable();
            $table->string('status', 16)->default('running');
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['supervisor', 'pid']);
        });

        // ---- APM telemetry (Pulse-style entries / aggregates / values) ----

        $driver = $schema->getConnection()->getDriverName();

        $keyHash = function (Blueprint $table) use ($driver) {
            match ($driver) {
                'mariadb', 'mysql' => $table->char('key_hash', 16)->charset('binary')->virtualAs('unhex(md5(`key`))'),
                'pgsql' => $table->uuid('key_hash')->storedAs('md5("key")::uuid'),
                default => $table->string('key_hash'),
            };
        };

        $schema->create('vigilance_values', function (Blueprint $table) use ($keyHash) {
            $table->id();
            $table->unsignedInteger('timestamp');
            $table->string('type');
            $table->mediumText('key');
            $keyHash($table);
            $table->mediumText('value');

            $table->index('timestamp');
            $table->index('type');
            $table->unique(['type', 'key_hash']);
        });

        $schema->create('vigilance_entries', function (Blueprint $table) use ($keyHash) {
            $table->id();
            $table->unsignedInteger('timestamp');
            $table->string('type');
            $table->mediumText('key');
            $keyHash($table);
            $table->bigInteger('value')->nullable();

            $table->index('timestamp');
            $table->index('type');
            $table->index('key_hash');
            $table->index(['timestamp', 'type', 'key_hash', 'value']);
        });

        $schema->create('vigilance_aggregates', function (Blueprint $table) use ($keyHash) {
            $table->id();
            $table->unsignedInteger('bucket');
            $table->unsignedMediumInteger('period');
            $table->string('type');
            $table->mediumText('key');
            $keyHash($table);
            $table->string('aggregate');
            $table->decimal('value', 20, 2);
            $table->unsignedInteger('count')->nullable();

            $table->unique(['bucket', 'period', 'type', 'aggregate', 'key_hash']);
            $table->index(['period', 'bucket']);
            $table->index('type');
            $table->index(['period', 'type', 'aggregate', 'bucket']);
        });

        // ---- Tracing (per-request/job span timelines, sampled) ----

        $schema->create('vigilance_traces', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('type', 16)->index();
            $table->string('name')->index();
            $table->string('status', 16);
            $table->unsignedInteger('duration_ms')->index();
            $table->unsignedInteger('span_count')->default(0);
            $table->unsignedInteger('dropped_spans')->default(0);
            $table->string('user_id')->nullable()->index();
            $table->unsignedInteger('started_at')->index();
            $table->json('attributes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['type', 'started_at']);
            $table->index(['status', 'started_at']);
        });

        $schema->create('vigilance_spans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('trace_id', 36)->index();
            $table->string('parent_id', 36)->nullable();
            $table->string('type', 16);
            $table->text('label');
            $table->unsignedBigInteger('start_us');
            $table->unsignedBigInteger('duration_us');
            $table->json('attributes')->nullable();
        });

        // ---- Deployment markers (correlate metrics/errors to a release) ----

        $schema->create('vigilance_deployments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('version')->nullable();
            $table->string('commit', 64)->nullable();
            $table->string('environment')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamp('deployed_at')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());

        $schema->dropIfExists('vigilance_deployments');
        $schema->dropIfExists('vigilance_spans');
        $schema->dropIfExists('vigilance_traces');
        $schema->dropIfExists('vigilance_aggregates');
        $schema->dropIfExists('vigilance_entries');
        $schema->dropIfExists('vigilance_values');
        $schema->dropIfExists('vigilance_workers');
        $schema->dropIfExists('vigilance_monitored_tags');
        $schema->dropIfExists('vigilance_supervisors');
        $schema->dropIfExists('vigilance_audit');
        $schema->dropIfExists('vigilance_scheduled_tasks');
        $schema->dropIfExists('vigilance_metric_snapshots');
        $schema->dropIfExists('vigilance_failure_groups');
        $schema->dropIfExists('vigilance_run_tags');
        $schema->dropIfExists('vigilance_runs');
    }
};
