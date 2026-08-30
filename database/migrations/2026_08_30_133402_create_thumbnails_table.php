<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thumbnails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('frame_second', 8, 3);
            $table->string('line1')->nullable();
            $table->string('line2')->nullable();
            $table->string('line3')->nullable();
            $table->unsignedSmallInteger('font_size')->default(132);
            $table->unsignedSmallInteger('pos_y')->default(58);
            $table->string('align', 10)->default('left');
            $table->unsignedTinyInteger('vignette')->default(55);
            $table->unsignedSmallInteger('contrast')->default(118);
            $table->unsignedSmallInteger('saturation')->default(72);
            $table->string('path')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thumbnails');
    }
};
