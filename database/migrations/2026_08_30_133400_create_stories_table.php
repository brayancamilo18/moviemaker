<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('mode', 20);
            $table->string('lore_slug')->nullable();
            $table->string('lore_name')->nullable();
            $table->text('premise')->nullable();
            $table->string('status', 32)->index();

            $table->string('verdict', 16)->nullable();
            $table->decimal('score', 3, 1)->nullable();

            $table->decimal('narration_seconds', 8, 3)->nullable();
            $table->decimal('master_seconds', 8, 3)->nullable();
            $table->decimal('video_seconds', 8, 3)->nullable();
            $table->unsignedSmallInteger('scene_count')->nullable();
            $table->unsignedSmallInteger('sentence_count')->nullable();
            $table->unsignedSmallInteger('shot_count')->nullable();
            $table->unsignedSmallInteger('effect_count')->nullable();

            $table->decimal('lufs', 5, 2)->nullable();
            $table->decimal('true_peak', 5, 2)->nullable();
            $table->decimal('figure_ratio', 5, 2)->nullable();
            $table->decimal('detail_ratio', 5, 2)->nullable();

            $table->string('yt_title')->nullable();
            $table->text('yt_description')->nullable();
            $table->text('yt_tags')->nullable();
            $table->json('yt_hashtags')->nullable();

            $table->string('discard_reason', 32)->nullable();
            $table->text('discard_note')->nullable();
            $table->string('failed_step', 40)->nullable();
            $table->text('failed_message')->nullable();

            $table->decimal('llm_cost_usd', 8, 4)->default(0);
            $table->boolean('used_fallback')->default(false);

            $table->string('published_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
