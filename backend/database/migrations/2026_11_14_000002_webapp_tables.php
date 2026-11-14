<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The web app's own data, next to the bot tables. Everything here is NEW and starts with `wa_`,
 * so it never collides with a bot table. People are the bot's `users` rows (id = Telegram id);
 * there are no foreign keys into bot tables, so the bot can delete its rows as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What the bot schema has no place for: Telegram profile, daily streak, the web app's own block flag.
        Schema::create('wa_user_profiles', function (Blueprint $t) {
            $t->bigInteger('user_id')->primary(); // users.id (Telegram id)
            $t->string('first_name', 128)->nullable();
            $t->string('last_name', 128)->nullable();
            $t->string('language_code', 12)->nullable();
            $t->string('photo_url')->nullable();
            $t->unsignedInteger('streak')->default(0);
            $t->date('last_bonus_on')->nullable();
            $t->timestamp('referral_rewarded_at')->nullable();
            $t->boolean('is_blocked')->default(false);
            $t->timestamps();
        });

        // The app catalog. A row linked to a bot model (bot_model_id) takes its name, price and plan from `models`.
        Schema::create('wa_models', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('bot_model_id')->nullable()->index(); // models.id
            $t->string('slug');
            $t->string('kind', 16); // text | image | video | song | tool
            $t->string('name');
            $t->string('hint')->nullable();
            $t->unsignedInteger('price');
            $t->string('badge')->nullable();
            $t->boolean('is_fast')->default(false);
            $t->string('min_plan', 16)->default('free');
            $t->string('driver', 32)->default('fake');
            $t->string('provider_model')->nullable();
            $t->json('options')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamp('edited_at')->nullable();
            $t->timestamps();
            $t->unique(['kind', 'slug']);
        });

        // Photosets. A row linked to a bot prompt (prompt_id) takes its name, prompt, trend flag and position from `prompts`.
        Schema::create('wa_photosets', function (Blueprint $t) {
            $t->id();
            $t->integer('prompt_id')->nullable()->index(); // prompts.id
            $t->string('name');
            $t->text('description')->nullable();
            $t->text('prompt');
            $t->string('cover_url')->nullable();
            $t->unsignedInteger('seed')->default(1);
            $t->unsignedTinyInteger('palette')->default(0);
            $t->boolean('is_trending')->default(false);
            $t->string('trend_hint')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamp('edited_at')->nullable();
            $t->timestamps();
        });

        Schema::create('wa_news', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('subtitle')->nullable();
            $t->string('sticker', 32)->nullable();
            $t->string('style', 16)->default('ember');
            $t->string('action', 32)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamp('ends_at')->nullable();
            $t->timestamp('edited_at')->nullable();
            $t->timestamps();
        });

        Schema::create('wa_settings', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        // A payment while it is in progress. Once paid it is also written to the bot table `payments` (bot_payment_id).
        Schema::create('wa_payments', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('user_id')->index();
            $t->string('provider', 16)->default('yookassa');
            $t->string('provider_payment_id')->nullable()->unique();
            $t->string('idempotence_key', 64)->unique();
            $t->string('purpose', 16); // topup | plan
            $t->unsignedInteger('tokens')->default(0);
            $t->string('plan', 16)->nullable();
            $t->string('period', 8)->nullable();
            $t->decimal('amount', 10, 2);
            $t->decimal('rub_amount', 10, 2)->nullable();
            $t->string('currency', 3)->default('RUB');
            $t->string('status', 16)->default('pending');
            $t->text('confirmation_url')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamp('credited_at')->nullable();
            $t->bigInteger('bot_payment_id')->nullable(); // payments.id
            $t->json('raw')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
            $t->index(['status', 'created_at']);
        });

        Schema::create('wa_generations', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('user_id');
            $t->string('kind', 16);
            $t->foreignId('ai_model_id')->nullable()->constrained('wa_models')->nullOnDelete();
            $t->foreignId('photoset_id')->nullable()->constrained('wa_photosets')->nullOnDelete();
            $t->string('tool', 32)->nullable();
            $t->text('prompt')->nullable();
            $t->json('params')->nullable();
            $t->string('input_path')->nullable();
            $t->string('provider_task_id')->nullable();
            $t->unsignedInteger('cost');
            $t->string('status', 16)->default('queued');
            $t->string('result_path')->nullable();
            $t->string('result_text', 4000)->nullable();
            $t->string('error')->nullable();
            $t->boolean('is_favorite')->default(false);
            $t->smallInteger('rating')->nullable();
            $t->bigInteger('query_log_id')->nullable(); // query_logs.id
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
            $t->index('status');
            $t->index(['status', 'started_at']);
            $t->index('created_at');
        });

        Schema::create('wa_chats', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('user_id');
            $t->string('title')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'updated_at']);
        });

        Schema::create('wa_chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chat_id')->constrained('wa_chats')->cascadeOnDelete();
            $t->string('role', 12);
            $t->text('content');
            $t->unsignedInteger('cost')->default(0);
            $t->bigInteger('query_log_id')->nullable();
            $t->timestamps();
            $t->index('created_at');
        });

        // The history of every balance change. `buckets` tells which bot counters changed: tokens, sub_tokens, temp_tokens.
        Schema::create('wa_transactions', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('user_id');
            $t->string('type', 16);
            $t->integer('amount');
            $t->unsignedBigInteger('balance_after');
            $t->json('buckets')->nullable();
            $t->string('title');
            $t->string('details')->nullable();
            $t->foreignId('generation_id')->nullable()->constrained('wa_generations')->nullOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained('wa_payments')->nullOnDelete();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });

        // neurogpt:import-legacy: which row of the old web app database became which row here. Makes the import repeatable.
        Schema::create('wa_legacy_map', function (Blueprint $t) {
            $t->id();
            $t->string('source_table', 32);
            $t->unsignedBigInteger('source_id');
            $t->string('target_table', 32);
            $t->bigInteger('target_id');
            $t->timestamp('created_at')->nullable();
            $t->unique(['source_table', 'source_id']);
        });
    }

    public function down(): void
    {
        foreach (['wa_legacy_map', 'wa_transactions', 'wa_chat_messages', 'wa_chats', 'wa_generations', 'wa_payments',
            'wa_settings', 'wa_news', 'wa_photosets', 'wa_models', 'wa_user_profiles'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
