<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Story>
 */
final class StoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = 'La cadena bajo el ingenio';

        return [
            'slug' => now()->format('Y-m-d').'-'.Str::slug($title).'-'.fake()->unique()->numerify('####'),
            'title' => $title,
            'mode' => StoryMode::Folklore,
            'lore_slug' => 'el-silbon',
            'lore_name' => 'El Silbón',
            'premise' => 'Un caminante oye un silbido que se aleja cuando se acerca al cañaveral del ingenio abandonado.',
            'status' => StoryStatus::Draft,
            'llm_cost_usd' => 0,
            'used_fallback' => false,
        ];
    }
}
