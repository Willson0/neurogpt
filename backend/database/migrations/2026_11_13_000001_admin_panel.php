<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Values changed in the admin panel. They override config/neurogpt.php on every boot.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Rows the admin has touched are no longer overwritten by the seeder.
        foreach (['ai_models', 'photosets', 'news'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestamp('edited_at')->nullable());
        }

        Schema::table('generations', fn (Blueprint $table) => $table->index('created_at'));
        Schema::table('chat_messages', fn (Blueprint $table) => $table->index('created_at'));
    }

    public function down(): void
    {
        Schema::table('chat_messages', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('generations', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        foreach (['ai_models', 'photosets', 'news'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('edited_at'));
        }
        Schema::dropIfExists('settings');
    }
};
