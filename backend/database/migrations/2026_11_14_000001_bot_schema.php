<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of the Telegram bot (its models.py, Tortoise ORM). They belong to the bot.
 *
 * On MySQL the developer's bot schema must already exist. SQLite gets local and test fixtures.
 * Existing tables are never altered or dropped,
 * and down() is empty on purpose.
 *
 * Column names and types repeat what Tortoise generates for MySQL (`generate_schemas`), including the
 * `_id` suffix of foreign keys (`text_model` -> `text_model_id`, `prompt_id` -> `prompt_id_id`).
 * Tortoise keeps defaults in Python, not in the database, so the web app always writes every NOT NULL column itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The old web app schema had its own `users` table. Pointing the new code at that database would mix them up.
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'tokens')) {
            throw new RuntimeException(
                'The table `users` here is not the bot table from models.py (no `tokens` column). '
                .'DB_* in .env must point to the bot database; the old web app database goes to LEGACY_DB_* for neurogpt:import-legacy.'
            );
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            foreach (['admins', 'config', 'shop', 'users', 'models', 'payments', 'prompts', 'query_logs', 'roles'] as $table) {
                if (! Schema::hasTable($table)) {
                    throw new RuntimeException("Bot table `$table` is missing. Install the developer's bot schema before starting the web app.");
                }
            }

            return;
        }

        $this->create('admins', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('username', 64);
        });

        $this->create('config', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('category', 255);
            $t->string('name', 255);
            $t->string('value', 1000);
            $t->dateTime('created_at', 6);
            $t->dateTime('modified_at', 6);
        });

        $this->create('shop', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->boolean('active');
            $t->string('text_name', 128);
            $t->string('category', 128);
            $t->string('item', 128);
            $t->integer('amount');
            $t->integer('price');
            $t->longText('description')->nullable();
            $t->string('tag', 32)->nullable();
            $t->dateTime('created_at', 6);
            $t->dateTime('modified_at', 6);
        });

        $this->create('logs_table', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->bigInteger('user_id');
            $t->string('action', 256);
            $t->string('params', 256)->nullable();
            $t->string('event_type', 50)->nullable();
            $t->dateTime('created_at', 6);
            $t->dateTime('modified_at', 6);
        });

        $this->create('models', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('type', 100);
            $t->boolean('can_image');
            $t->integer('price');
            $t->string('name', 100);
            $t->string('text_name', 100);
            $t->string('tag', 500);
            $t->boolean('only_sub')->nullable()->default(false);
            $t->json('params');
            $t->dateTime('created_at', 6);
            $t->dateTime('modified_at', 6);
        });

        $this->create('payment_methods', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('payment_method_id', 256);
            $t->string('first6', 7);
            $t->string('last4', 5);
            $t->string('card_type', 24);
            $t->dateTime('created_at', 6);
        });

        $this->create('promos', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name', 255);
            $t->integer('discount');
            $t->integer('uses');
            $t->dateTime('created_at', 6);
            $t->dateTime('modified_at', 6);
        });

        $this->create('prompts', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('position')->nullable();
            $t->string('name', 64);
            $t->longText('prompt');
            $t->string('photo_example', 512)->nullable();
            $t->string('model', 50)->nullable();
            $t->boolean('trend');
        });

        $this->create('links', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name', 64);
            $t->boolean('hide');
            $t->dateTime('created_at', 6);
            $t->dateTime('modified_at', 6);
            $t->unsignedInteger('prompt_id_id')->nullable();
            $t->foreign('prompt_id_id')->references('id')->on('prompts')->cascadeOnDelete();
        });

        $this->create('query_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->bigInteger('user_id');
            $t->string('model', 150);
            $t->string('type', 50);
            $t->integer('token_used');
            $t->longText('prompt');
            $t->longText('answer')->nullable();
            $t->string('attachments', 250)->nullable();
            $t->boolean('was_image_deleted');
            $t->dateTime('created_at', 6);
            $t->dateTime('modified_at', 6);
        });

        $this->create('op', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->bigInteger('channel_id');
            $t->boolean('check_sub');
            $t->string('link', 256);
            $t->dateTime('created_at', 6);
            $t->dateTime('modified_at', 6);
        });

        $this->create('roles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name', 64);
            $t->longText('prompt');
        });

        $this->create('users', function (Blueprint $t) {
            // Telegram user id. Tortoise marks it auto-increment, the bot always sets it explicitly.
            $t->bigIncrements('id');
            $t->string('username', 64)->nullable();
            $t->string('email', 100)->nullable();
            $t->double('paid_money');
            $t->boolean('is_premium');
            $t->boolean('blocked_us');
            $t->integer('tokens');
            $t->integer('sub_tokens');
            $t->integer('temp_tokens');
            $t->bigInteger('temp_tokens_until');
            $t->boolean('save_context');
            $t->string('sub', 12);
            $t->bigInteger('start_sub_time');
            $t->bigInteger('end_sub_time');
            $t->string('autocont_sub', 12);
            $t->integer('autocont_try')->nullable();
            $t->boolean('is_trial_sub');
            $t->boolean('tried_free_smart');
            $t->boolean('auto_continue');
            $t->boolean('was_subed');
            $t->string('link', 100)->nullable();
            $t->bigInteger('referal_user_from')->nullable();
            $t->boolean('ref_paid');
            $t->integer('ref_balance');
            $t->string('last_notif', 10)->nullable();
            $t->dateTime('last_activity_at', 6);
            $t->dateTime('created_at', 6);
            $t->unsignedBigInteger('last_payment_method_id')->nullable();
            $t->unsignedBigInteger('photo_model_id');
            $t->unsignedBigInteger('text_model_id');
            $t->unsignedBigInteger('text_role_id')->nullable();
            $t->unsignedBigInteger('video_model_id');
            $t->foreign('last_payment_method_id')->references('id')->on('payment_methods')->cascadeOnDelete();
            $t->foreign('photo_model_id')->references('id')->on('models')->cascadeOnDelete();
            $t->foreign('text_model_id')->references('id')->on('models')->cascadeOnDelete();
            $t->foreign('text_role_id')->references('id')->on('roles')->cascadeOnDelete();
            $t->foreign('video_model_id')->references('id')->on('models')->cascadeOnDelete();
        });

        $this->create('payments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('payment_id', 128);
            $t->boolean('is_autopayment');
            $t->boolean('was_bought');
            $t->boolean('is_proceed');
            $t->double('total');
            $t->string('total_valut', 16);
            $t->double('rub_total');
            $t->dateTime('created_at', 6);
            $t->dateTime('modified_at', 6);
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('recipient_id');
            $t->unsignedBigInteger('user_id');
            $t->foreign('item_id')->references('id')->on('shop')->cascadeOnDelete();
            $t->foreign('recipient_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $this->create('veo3_prompts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('hash', 300);
            $t->string('status', 50);
            $t->string('model', 50);
            $t->string('task_id', 300)->nullable();
            $t->json('prompts');
            $t->integer('tokens_used');
            $t->dateTime('created_at', 6);
            $t->dateTime('modified_at', 6);
            $t->unsignedBigInteger('user_id');
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /** Never drops anything: these tables belong to the bot. */
    public function down(): void {}

    private function create(string $name, Closure $columns): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $t) use ($columns) {
                $t->charset = 'utf8mb4';
                $columns($t);
            });
        }
    }
};
