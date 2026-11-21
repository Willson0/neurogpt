<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL commits each ALTER independently, even if the migration fails before it is recorded.
        $columns = [
            'shop_item_id' => fn (Blueprint $table) => $table->unsignedBigInteger('shop_item_id')->nullable(),
            'plan_days' => fn (Blueprint $table) => $table->unsignedInteger('plan_days')->nullable(),
            'plan_name' => fn (Blueprint $table) => $table->string('plan_name', 128)->nullable(),
        ];
        foreach ($columns as $column => $add) {
            if (Schema::hasColumn('wa_payments', $column)) {
                continue;
            }

            try {
                Schema::table('wa_payments', $add);
            } catch (QueryException $e) {
                // A second deploy can add the same column after the schema check.
                if ((int) ($e->errorInfo[1] ?? 0) !== 1060 || ! Schema::hasColumn('wa_payments', $column)) {
                    throw $e;
                }
            }
        }

    }

    public function down(): void
    {
        // These columns may belong to an earlier deployment and contain paid order data.
        throw new RuntimeException('This migration cannot remove existing subscription payment data.');
    }
};
