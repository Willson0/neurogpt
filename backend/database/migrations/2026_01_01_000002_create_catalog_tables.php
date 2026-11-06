<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->string('kind', 16); // text | image | video
            $table->string('name');
            $table->string('hint')->nullable();
            $table->unsignedInteger('price');
            $table->string('badge')->nullable();
            $table->boolean('is_fast')->default(false);
            $table->string('min_plan', 16)->default('free');
            $table->string('driver', 32)->default('fake');
            $table->string('provider_model')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['kind', 'slug']);
        });

        Schema::create('photosets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('prompt');
            $table->string('cover_url')->nullable();
            $table->unsignedInteger('seed')->default(1);
            $table->unsignedTinyInteger('palette')->default(0);
            $table->boolean('is_trending')->default(false);
            $table->string('trend_hint')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('sticker', 32)->nullable();
            $table->string('style', 16)->default('ember'); // ember | ink | gold
            $table->string('action', 32)->nullable(); // topup | models | invite | plans
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news');
        Schema::dropIfExists('photosets');
        Schema::dropIfExists('ai_models');
    }
};
