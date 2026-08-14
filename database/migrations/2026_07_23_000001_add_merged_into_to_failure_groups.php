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

        if ($schema->hasColumn('vigilance_failure_groups', 'merged_into')) {
            return;
        }

        $schema->table('vigilance_failure_groups', function (Blueprint $table) {
            // Set when an issue is manually merged into another (its canonical
            // group). Merged issues are hidden from the inbox and new occurrences
            // of their signature are redirected to the target.
            $table->unsignedBigInteger('merged_into')->nullable()->index()->after('resolved_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());

        if ($schema->hasColumn('vigilance_failure_groups', 'merged_into')) {
            $schema->table('vigilance_failure_groups', function (Blueprint $table) {
                // SQLite rebuilds the table on a column drop and re-validates
                // every index against the new shape, so an index still pointing
                // at the dropped column aborts the rollback. Drop it first.
                $table->dropIndex(['merged_into']);
                $table->dropColumn('merged_into');
            });
        }
    }
};
