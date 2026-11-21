<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumn('wa_photosets', 'kind', fn (Blueprint $table) => $table->string('kind', 8)->default('image'));
        $columns = [
            'renew_for_expiry' => fn (Blueprint $table) => $table->unsignedBigInteger('renew_for_expiry')->nullable(),
            'renew_opt_in' => fn (Blueprint $table) => $table->boolean('renew_opt_in')->default(false),
            'renew_due_at' => fn (Blueprint $table) => $table->unsignedBigInteger('renew_due_at')->nullable(),
            'renew_base_days' => fn (Blueprint $table) => $table->unsignedSmallInteger('renew_base_days')->nullable(),
            'renew_schedule' => fn (Blueprint $table) => $table->json('renew_schedule')->nullable(),
            'renew_max_rub' => fn (Blueprint $table) => $table->decimal('renew_max_rub', 12, 2)->nullable(),
            'renew_method' => fn (Blueprint $table) => $table->string('renew_method', 256)->nullable(),
            'promo_source' => fn (Blueprint $table) => $table->string('promo_source', 8)->nullable(),
            'promo_id' => fn (Blueprint $table) => $table->unsignedBigInteger('promo_id')->nullable(),
            'promo_code' => fn (Blueprint $table) => $table->string('promo_code', 64)->nullable(),
            'promo_released' => fn (Blueprint $table) => $table->boolean('promo_released')->default(false),
        ];
        foreach ($columns as $column => $add) {
            $this->addColumn('wa_payments', $column, $add);
        }
        if (! Schema::hasIndex('wa_payments', 'wa_payment_renewal_attempt', 'unique')) {
            Schema::table('wa_payments', fn (Blueprint $table) => $table
                ->unique(['user_id', 'renew_for_expiry', 'plan_days'], 'wa_payment_renewal_attempt'));
        }
        if (! Schema::hasTable('wa_fixed_promos')) {
            Schema::create('wa_fixed_promos', function (Blueprint $table) {
                $table->id();
                $table->string('name', 64)->unique();
                $table->unsignedInteger('discount');
                $table->unsignedInteger('uses');
                $table->timestamps();
            });
        }
    }

    private function addColumn(string $table, string $column, Closure $add): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }

        try {
            Schema::table($table, $add);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060 || ! Schema::hasColumn($table, $column)) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        // The columns can contain renewal authorizations and already executed payment attempts.
        throw new RuntimeException('This migration cannot remove checkout history.');
    }
};
