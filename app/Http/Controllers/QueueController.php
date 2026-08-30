<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Llm\SpendReport;
use App\Services\Pipeline\QueueHealth;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Inertia\Response;
use Inertia\ResponseFactory;

final class QueueController extends Controller
{
    public function __construct(
        private readonly ResponseFactory $inertia,
        private readonly SpendReport $spend,
    ) {}

    public function index(QueueHealth $queue): Response
    {
        $attention = Story::query()
            ->where('status', StoryStatus::PendingReview)
            ->orderBy('updated_at')
            ->get();

        $rest = Story::query()
            ->whereNotIn('status', [
                StoryStatus::PendingReview,
                StoryStatus::Discarded,
            ])
            ->orderByDesc('updated_at')
            ->get();

        $usedCounts = $this->usedCounts();

        return $this->inertia->render('Queue', [
            'stats' => $this->stats(),
            'attention' => $attention
                ->map(fn (Story $story): array => $this->serialize($story, $usedCounts))
                ->values()
                ->all(),
            'rest' => $rest
                ->map(fn (Story $story): array => $this->serialize($story, $usedCounts))
                ->values()
                ->all(),
            'queue' => $queue->status(),
        ]);
    }

    /**
     * @return list<array{label: string, value: int|string, note: string, title?: string}>
     */
    private function stats(): array
    {
        $pending = Story::query()->where('status', StoryStatus::PendingReview)->count();
        $ready = Story::query()->where('status', StoryStatus::ReadyToPublish)->count();
        $published = Story::query()
            ->where('status', StoryStatus::Published)
            ->whereBetween('published_at', [
                now()->copy()->startOfMonth(),
                now()->copy()->endOfMonth(),
            ])
            ->count();

        $spend = $this->spend->forMonth();

        return [
            [
                'label' => 'Pendientes de revisión',
                'value' => $pending,
                'note' => $pending > 0 ? 'reclaman a una persona' : 'nada que aprobar',
            ],
            [
                'label' => 'Listas sin descargar',
                'value' => $ready,
                'note' => $ready > 0 ? 'aprobadas, aún en el disco' : '—',
            ],
            [
                'label' => 'Publicadas este mes',
                'value' => $published,
                'note' => mb_strtolower(now()->copy()->locale('es')->isoFormat('MMMM')),
            ],
            [
                'label' => 'Gasto del mes',
                'value' => $spend['euro'],
                'note' => $spend['note'],
                'title' => $spend['title'],
            ],
        ];
    }

    /**
     * @return Collection<string, int>
     */
    private function usedCounts(): Collection
    {
        return Story::query()
            ->whereNotNull('lore_slug')
            ->selectRaw('lore_slug, COUNT(*) as used_count')
            ->groupBy('lore_slug')
            ->pluck('used_count', 'lore_slug')
            ->map(fn (mixed $count): int => (int) $count);
    }

    /**
     * @param  Collection<string, int>  $usedCounts
     * @return array{id: int, slug: string, t: string, mode: string, cr: string, dur: string, st: string, stColor: string, v: string, sc: float, d: string, usedCount: int, href: string, tone: int}
     */
    private function serialize(Story $story, Collection $usedCounts): array
    {
        $loreSlug = $story->lore_slug;

        return [
            'id' => $story->id,
            'slug' => $story->slug,
            't' => $story->title ?? '',
            'mode' => $story->mode->label(),
            'cr' => $story->lore_name ?? '—',
            'dur' => $this->formatDuration($story->master_seconds),
            'st' => $story->status->label(),
            'stColor' => $story->status->color(),
            'v' => $story->verdict?->value ?? '—',
            'sc' => $story->score ?? 0.0,
            'd' => $this->formatDate($story->updated_at),
            'usedCount' => $loreSlug === null ? 0 : (int) $usedCounts->get($loreSlug, 0),
            'href' => $this->href($story),
            'tone' => crc32($story->slug) % 256,
        ];
    }

    private function formatDuration(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $total = (int) floor($seconds);

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    private function formatDate(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '—';
        }

        return rtrim(mb_strtolower($at->copy()->locale('es')->translatedFormat('j M')), '.');
    }

    private function href(Story $story): string
    {
        $reachedReview = in_array($story->status, [
            StoryStatus::PendingReview,
            StoryStatus::ReadyToPublish,
            StoryStatus::Downloaded,
            StoryStatus::Published,
        ], true);

        return $reachedReview
            ? route('review.show', $story)
            : route('pipeline.show', $story);
    }
}
