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

        if ($schema->hasTable('vigilance_sourcemaps')) {
            return;
        }

        $schema->create('vigilance_sourcemaps', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('release')->index();
            $table->string('file');            // the generated JS filename (e.g. app-abc123.js)
            $table->longText('content');       // the source map JSON
            $table->timestamp('created_at')->nullable();

            $table->unique(['release', 'file']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists('vigilance_sourcemaps');
    }
};
