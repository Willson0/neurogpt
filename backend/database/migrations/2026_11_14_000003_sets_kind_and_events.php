<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only the app's own wa_* tables are touched here, never the bot tables.
 *  - wa_photosets.kind: "photo" (a picture from the user's photo) or "video" (a video set: a prompt for video generation);
 *  - wa_events: button presses and screen views of the Mini App for the admin analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wa_photosets', 'kind')) {
            Schema::table('wa_photosets', function (Blueprint $t) {
                $t->string('kind', 8)->default('photo')->after('prompt_id')->index();
            });
        }

        if (! Schema::hasTable('wa_events')) {
            Schema::create('wa_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable(); // users.id (Telegram id), no FK into the bot table
                $t->string('type', 8); // click | view
                $t->string('name', 64);
                $t->string('screen', 48)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['created_at', 'type']);
                $t->index(['type', 'name']);
                $t->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_events');
        if (Schema::hasColumn('wa_photosets', 'kind')) {
            Schema::table('wa_photosets', function (Blueprint $t) {
                $t->dropIndex(['kind']);
                $t->dropColumn('kind');
            });
        }
    }
};
