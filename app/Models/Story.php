<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscardReason;
use App\Enums\ReviewVerdict;
use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Exceptions\InvalidStoryTransition;
use Database\Factories\StoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Story extends Model
{
    /** @use HasFactory<StoryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    private const ARTIFACTS = [
        'narration.wav',
        'narration.mp3',
        'timings.json',
        'shots.json',
        'sounds.json',
        'subtitles.srt',
        'silent.mp4',
        'video.mp4',
        'video-nograde.mp4',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'title',
        'mode',
        'lore_slug',
        'lore_name',
        'premise',
        'status',
        'verdict',
        'score',
        'narration_seconds',
        'master_seconds',
        'video_seconds',
        'scene_count',
        'sentence_count',
        'shot_count',
        'effect_count',
        'lufs',
        'true_peak',
        'figure_ratio',
        'detail_ratio',
        'yt_title',
        'yt_description',
        'yt_tags',
        'yt_hashtags',
        'discard_reason',
        'discard_note',
        'failed_step',
        'failed_message',
        'llm_cost_usd',
        'used_fallback',
        'published_url',
        'published_at',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StoryStatus::class,
            'mode' => StoryMode::class,
            'verdict' => ReviewVerdict::class,
            'discard_reason' => DiscardReason::class,
            'yt_hashtags' => 'array',
            'published_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'score' => 'float',
            'narration_seconds' => 'float',
            'master_seconds' => 'float',
            'video_seconds' => 'float',
            'lufs' => 'float',
            'true_peak' => 'float',
            'figure_ratio' => 'float',
            'detail_ratio' => 'float',
            'llm_cost_usd' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<StoryEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(StoryEvent::class)->orderByDesc('created_at');
    }

    /**
     * @return HasMany<Thumbnail, $this>
     */
    public function thumbnails(): HasMany
    {
        return $this->hasMany(Thumbnail::class);
    }

    /**
     * @return HasOne<Thumbnail, $this>
     */
    public function selectedThumbnail(): HasOne
    {
        return $this->hasOne(Thumbnail::class)->where('is_selected', true);
    }

    /**
     * @param  Builder<Story>  $query
     * @return Builder<Story>
     */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('status', StoryStatus::PendingReview);
    }

    /**
     * @param  Builder<Story>  $query
     * @return Builder<Story>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            StoryStatus::Published,
            StoryStatus::Discarded,
        ]);
    }

    /**
     * @param  Builder<Story>  $query
     * @return Builder<Story>
     */
    public function scopeStatus(Builder $query, StoryStatus $s): Builder
    {
        return $query->where('status', $s);
    }

    public function directory(): string
    {
        return storage_path('app/stories/'.$this->slug);
    }

    public function artifact(string $name): ?string
    {
        if (! in_array($name, self::ARTIFACTS, true)) {
            return null;
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    public function hasArtifact(string $name): bool
    {
        return $this->artifact($name) !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transitionTo(StoryStatus $next, ?string $note = null, array $payload = []): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new InvalidStoryTransition($this->status, $next);
        }

        $from = $this->status;

        $this->getConnection()->transaction(function () use ($from, $next, $note, $payload): void {
            $this->update(['status' => $next]);

            $this->events()->create([
                'type' => 'status_changed',
                'from_status' => $from->value,
                'to_status' => $next->value,
                'note' => $note,
                'payload' => $payload,
            ]);
        });
    }
}
