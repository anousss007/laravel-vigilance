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

        if ($schema->hasTable('vigilance_user_feedback')) {
            return;
        }

        $schema->create('vigilance_user_feedback', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('message');
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('url')->nullable();
            $table->string('trace_id', 36)->nullable()->index();
            $table->string('user')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists('vigilance_user_feedback');
    }
};
