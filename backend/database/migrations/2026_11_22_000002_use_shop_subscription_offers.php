<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_item_id')->nullable();
            $table->unsignedInteger('plan_days')->nullable();
            $table->string('plan_name', 128)->nullable();
        });

        // Only upgrade old local SQLite copies. The bot owns the shared MySQL schema.
        if (DB::connection()->getDriverName() !== 'sqlite' || ! Schema::hasColumn('models', 'allowed_subs')
            || Schema::hasColumn('models', 'is_sub')) {
            return;
        }

        Schema::table('models', fn (Blueprint $table) => $table->boolean('is_sub')->default(false));
        DB::table('models')->select(['id', 'allowed_subs'])->chunkById(100, function ($models) {
            foreach ($models as $model) {
                $subs = json_decode((string) $model->allowed_subs, true);
                if (is_array($subs) && $subs && ! in_array('free', $subs, true)) {
                    DB::table('models')->where('id', $model->id)->update(['is_sub' => true]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('wa_payments', fn (Blueprint $table) => $table->dropColumn(['shop_item_id', 'plan_days', 'plan_name']));
    }
};
