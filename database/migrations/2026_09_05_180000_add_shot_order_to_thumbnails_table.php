<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La tabla se diseñó para sacar la portada de un fotograma del vídeo, por su segundo. Las
     * candidatas salen de los planos ya generados, que existen mucho antes que el MP4 y se
     * identifican por su número. El segundo se queda —es dónde cae ese plano en el vídeo, y
     * sirve para ir a verlo— pero deja de ser obligatorio.
     */
    public function up(): void
    {
        Schema::table('thumbnails', function (Blueprint $table) {
            $table->unsignedSmallInteger('shot_order')->nullable()->after('story_id');
        });

        Schema::table('thumbnails', function (Blueprint $table) {
            $table->decimal('frame_second', 8, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('thumbnails', function (Blueprint $table) {
            $table->dropColumn('shot_order');
        });

        Schema::table('thumbnails', function (Blueprint $table) {
            $table->decimal('frame_second', 8, 3)->nullable(false)->change();
        });
    }
};
