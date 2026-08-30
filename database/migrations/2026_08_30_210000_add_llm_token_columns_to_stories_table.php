<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->unsignedBigInteger('llm_input_tokens')->default(0)->after('llm_cost_usd');
            $table->unsignedBigInteger('llm_output_tokens')->default(0)->after('llm_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn(['llm_input_tokens', 'llm_output_tokens']);
        });
    }
};
