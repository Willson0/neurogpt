<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only wa_payments (an app table) changes, the bot tables stay as they are.
 *  - shop_item_id / days: the bot `shop` row the payment was created from and the subscription length it gives;
 *  - referrer_id / ref_amount / ref_reverted: who got the 20% commission into users.ref_balance and how much of it
 *    was taken back after refunds (this is what makes a repeated webhook harmless);
 *  - refunded_amount / refunded_at: refunds made in YooKassa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('wa_payments', 'shop_item_id')) {
            return;
        }
        Schema::table('wa_payments', function (Blueprint $t) {
            $t->unsignedBigInteger('shop_item_id')->nullable()->after('user_id'); // shop.id, no FK into the bot table
            $t->unsignedInteger('days')->nullable()->after('period');
            $t->unsignedBigInteger('referrer_id')->nullable()->after('bot_payment_id'); // users.id
            $t->integer('ref_amount')->default(0)->after('referrer_id');
            $t->integer('ref_reverted')->default(0)->after('ref_amount');
            $t->decimal('refunded_amount', 10, 2)->default(0)->after('ref_reverted');
            $t->timestamp('refunded_at')->nullable()->after('refunded_amount');
            $t->index('referrer_id');
        });
    }

    public function down(): void
    {
        Schema::table('wa_payments', function (Blueprint $t) {
            $t->dropIndex(['referrer_id']);
            $t->dropColumn(['shop_item_id', 'days', 'referrer_id', 'ref_amount', 'ref_reverted', 'refunded_amount', 'refunded_at']);
        });
    }
};
