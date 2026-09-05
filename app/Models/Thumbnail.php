<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Thumbnail extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'story_id',
        'name',
        'shot_order',
        'frame_second',
        'line1',
        'line2',
        'line3',
        'font_size',
        'pos_y',
        'align',
        'vignette',
        'contrast',
        'saturation',
        'path',
        'is_selected',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shot_order' => 'integer',
            'frame_second' => 'float',
            'font_size' => 'integer',
            'pos_y' => 'integer',
            'vignette' => 'integer',
            'contrast' => 'integer',
            'saturation' => 'integer',
            'is_selected' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Story, $this>
     */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
