<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('vigilance.storage.connection');
    }

    public function up(): void
    {
        // Rules created from the dashboard, next to the finding that prompted
        // them. The config file stays the place for rules you decide up front;
        // this is the place for the ones you only discover in production, where
        // "edit config/vigilance.php and redeploy" is the reason the noisy route
        // is still noisy three weeks later.
        Schema::connection($this->getConnection())->create('vigilance_suppressions', function (Blueprint $table) {
            $table->id();

            // What the rule applies to: route | query | cache_key | exception.
            $table->string('scope', 24)->index();

            // The pattern itself — a wildcard glob or a delimited regex, matched
            // exactly like the equivalent config list.
            $table->string('pattern');

            // 'ignore' drops the telemetry; 'group' rewrites the key so a
            // high-cardinality family collapses into one row.
            $table->string('action', 16)->default('ignore');

            // Replacement key for the 'group' action.
            $table->string('replacement')->nullable();

            $table->string('created_by')->nullable();
            $table->string('note')->nullable();

            // Suppressions are frequently meant to be temporary ("mute this
            // while we ship the fix"); an expiry makes that the default rather
            // than an intention nobody follows up on.
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['scope', 'pattern']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('vigilance_suppressions');
    }
};
