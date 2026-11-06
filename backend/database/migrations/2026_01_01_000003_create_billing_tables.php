<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16)->default('yookassa');
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('idempotence_key', 64)->unique();
            $table->string('purpose', 16); // topup | plan
            $table->unsignedInteger('tokens')->default(0);
            $table->string('plan', 16)->nullable();
            $table->string('period', 8)->nullable(); // month | year
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('RUB');
            $table->string('status', 16)->default('pending'); // pending | succeeded | canceled
            $table->text('confirmation_url')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // image | video | tool
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('photoset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool', 32)->nullable();
            $table->text('prompt')->nullable();
            $table->json('params')->nullable();
            $table->string('input_path')->nullable();
            $table->unsignedInteger('cost');
            $table->string('status', 16)->default('queued'); // queued | processing | done | failed | cancelled
            $table->string('result_path')->nullable();
            $table->string('result_text', 4000)->nullable();
            $table->string('error')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->smallInteger('rating')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12); // user | assistant
            $table->text('content');
            $table->unsignedInteger('cost')->default(0);
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // topup | spend | refund | bonus | subscription | referral
            $table->integer('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('title');
            $table->string('details')->nullable();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chats');
        Schema::dropIfExists('generations');
        Schema::dropIfExists('payments');
    }
};
