<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_generations', function (Blueprint $table) {
            $table->json('music_result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wa_generations', function (Blueprint $table) {
            $table->dropColumn('music_result');
        });
    }
};
