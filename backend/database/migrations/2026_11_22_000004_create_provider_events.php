<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_provider_events')) {
            return;
        }

        Schema::create('wa_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('host', 255)->nullable();
            $table->string('operation', 32);
            $table->string('outcome', 10);
            $table->string('reason', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('feature', 32)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider_code', 48)->nullable();
            $table->string('task_id', 128)->nullable();
            $table->string('request_id', 128)->nullable();
            $table->unsignedBigInteger('generation_id')->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['provider', 'outcome', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_provider_events');
    }
};
