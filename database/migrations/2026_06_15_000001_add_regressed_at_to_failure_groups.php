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

        if ($schema->hasColumn('vigilance_failure_groups', 'regressed_at')) {
            return;
        }

        $schema->table('vigilance_failure_groups', function (Blueprint $table) {
            // Set when a previously-resolved issue recurs — powers the
            // regression alert and the "regressed" badge in the Issues inbox.
            $table->timestamp('regressed_at')->nullable()->index()->after('resolved_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());

        if ($schema->hasColumn('vigilance_failure_groups', 'regressed_at')) {
            $schema->table('vigilance_failure_groups', function (Blueprint $table) {
                $table->dropColumn('regressed_at');
            });
        }
    }
};
