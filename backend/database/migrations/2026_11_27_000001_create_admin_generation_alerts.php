<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_admin_generation_alerts', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_id')->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_admin_generation_alerts');
    }
};
