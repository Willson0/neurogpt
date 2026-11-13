<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            // Model-specific settings: durations, formats, provider request family.
            $table->json('options')->nullable()->after('provider_model');
        });

        Schema::table('generations', function (Blueprint $table) {
            // Task id on the provider's side for jobs that are polled (kie.ai).
            $table->string('provider_task_id')->nullable()->after('input_path');
            $table->index(['status', 'started_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex(['status', 'created_at']));
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex(['status', 'started_at']);
            $table->dropColumn('provider_task_id');
        });
        Schema::table('ai_models', fn (Blueprint $table) => $table->dropColumn('options'));
    }
};
