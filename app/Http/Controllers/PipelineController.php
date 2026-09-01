<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Models\StoryEvent;
use App\Services\Llm\SpendReport;
use App\Services\Pipeline\PipelineProgress;
use App\Services\Pipeline\QueueHealth;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Response;
use Inertia\ResponseFactory;

final class PipelineController extends Controller
{
    /**
     * @var list<array{job: string, stage: string|null, name: string, noun: string}>
     */
    private const ROWS = [
        ['job' => 'script', 'stage' => 'generate', 'name' => 'Guion', 'noun' => 'escenas'],
        ['job' => 'script', 'stage' => 'review', 'name' => 'Revisión del guion', 'noun' => 'puntos'],
        ['job' => 'narration', 'stage' => null, 'name' => 'Narración', 'noun' => 'frases'],
        ['job' => 'images', 'stage' => 'plan', 'name' => 'Planificación de planos', 'noun' => 'planos'],
        ['job' => 'images', 'stage' => 'direct', 'name' => 'Dirección e imágenes', 'noun' => 'imágenes'],
        ['job' => 'sound', 'stage' => null, 'name' => 'Sonido y mezcla', 'noun' => 'efectos'],
        ['job' => 'render', 'stage' => null, 'name' => 'Render', 'noun' => 'planos'],
    ];

    /**
     * @var list<StoryStatus>
     */
    private const INACTIVE = [
        StoryStatus::PendingReview,
        StoryStatus::ReadyToPublish,
        StoryStatus::Downloaded,
        StoryStatus::Published,
        StoryStatus::Discarded,
    ];

    public function __construct(
        private readonly ResponseFactory $inertia,
        private readonly QueueHealth $queue,
        private readonly PipelineProgress $progress,
        private readonly SpendReport $spend,
    ) {}

    public function index(Request $request): Response
    {
        $active = $this->activeStories();

        return $this->inertia->render('Pipeline', $this->page(
            $active,
            $this->pickSelected($active, $request->query('story')),
        ));
    }

    public function show(Story $story): Response
    {
        $active = $this->activeStories();
        $story->loadMissing('events');

        return $this->inertia->render('Pipeline', $this->page($active, $story));
    }

    public function state(Request $request): JsonResponse
    {
        $active = $this->activeStories();

        return response()->json($this->page(
            $active,
            $this->pickSelected($active, $request->query('story')),
        ));
    }

    /**
     * @param  Collection<int, Story>  $active
     * @return array{active: list<array<string, mixed>>, selected: array<string, mixed>|null, queue: array<string, mixed>}
     */
    private function page(Collection $active, ?Story $selected): array
    {
        return [
            'active' => $active
                ->map(fn (Story $story): array => $this->summary($story))
                ->values()
                ->all(),
            'selected' => $selected instanceof Story ? $this->detail($selected) : null,
            'queue' => $this->queue->status(),
        ];
    }

    /**
     * @return Collection<int, Story>
     */
    private function activeStories(): Collection
    {
        return Story::query()
            ->with('events')
            ->whereNotIn('status', array_map(
                static fn (StoryStatus $status): string => $status->value,
                self::INACTIVE,
            ))
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @param  Collection<int, Story>  $active
     */
    private function pickSelected(Collection $active, mixed $id): ?Story
    {
        if (is_numeric($id)) {
            $match = $active->first(
                static fn (Story $story): bool => $story->id === (int) $id,
            );

            if ($match instanceof Story) {
                return $match;
            }
        }

        return $active->first();
    }

    /**
     * @return array{id: int, slug: string, title: string, status: array{value: string, label: string, color: string}, mode: string, lore_name: string|null, currentRow: int, failed: bool, tone: int}
     */
    private function summary(Story $story): array
    {
        $progress = $this->progress->get($story->id);
        $running = $this->runningRow($progress);
        $queued = $this->queuedRow($progress);
        $failedRow = $this->failedRow($story, $progress);
        $jobsDone = $this->jobsDone($story);

        return [
            'id' => $story->id,
            'slug' => $story->slug,
            'title' => $this->title($story),
            'status' => [
                'value' => $story->status->value,
                'label' => $story->status->label(),
                'color' => $story->status->color(),
            ],
            'mode' => $story->mode->label(),
            'lore_name' => $story->lore_name,
            'currentRow' => $this->currentRow($jobsDone, $running ?? $queued, $failedRow),
            'failed' => $story->status === StoryStatus::Failed,
            'tone' => crc32($story->slug) % 256,
        ];
    }

    /**
     * @return array{story: array<string, mixed>, rows: list<array<string, mixed>>, elapsed: string, backupCost: string, backupTokens: string}
     */
    private function detail(Story $story): array
    {
        $progress = $this->progress->get($story->id);
        $running = $this->runningRow($progress);
        $queued = $this->queuedRow($progress);
        $failedRow = $this->failedRow($story, $progress);
        $jobsDone = $this->jobsDone($story);
        $windows = $this->statusWindows($story);

        $rows = [];

        foreach (self::ROWS as $index => $row) {
            $number = $index + 1;
            $state = $this->rowState($number, $row, $jobsDone, $running, $queued, $failedRow);
            $rows[] = [
                'num' => sprintf('%02d', $number),
                'name' => $row['name'],
                'job' => $row['job'],
                'state' => $state,
                'progress' => $this->rowProgress($state, $progress, $running === $number),
                'unit' => $this->rowUnit($number, $state, $story, $progress, $row['noun'], $running === $number),
                'time' => $this->rowTime(
                    $row,
                    $state,
                    $windows,
                    $running === $number ? $progress['started_at'] ?? null : null,
                ),
                'error' => $failedRow === $number ? $story->failed_message : null,
            ];
        }

        return [
            'story' => [
                'id' => $story->id,
                'slug' => $story->slug,
                'title' => $this->title($story),
                'status' => $story->status->value,
                'status_label' => $story->status->label(),
                'status_color' => $story->status->color(),
                'mode' => $story->mode->label(),
                'lore_name' => $story->lore_name,
                'failed_step' => $story->failed_step,
                'failed_message' => $story->failed_message,
                'scene_count' => $story->scene_count,
                'score' => $story->score,
                'sentence_count' => $story->sentence_count,
                'shot_count' => $story->shot_count,
                'effect_count' => $story->effect_count,
                'lufs' => $story->lufs,
                'video_seconds' => $story->video_seconds,
                'verdict' => $story->verdict?->value,
                'used_fallback' => (bool) $story->used_fallback,
            ],
            'rows' => $rows,
            'elapsed' => $this->elapsed($story),
            'backupCost' => $this->spend->formatEuro((float) $story->llm_cost_usd),
            'backupTokens' => $this->backupTokens($story),
        ];
    }

    private function backupTokens(Story $story): string
    {
        $total = (int) $story->llm_input_tokens + (int) $story->llm_output_tokens;
        $amount = $total >= 1_000_000
            ? number_format($total / 1_000_000, 2, ',', '').' M tokens'
            : number_format($total, 0, ',', '.').' tokens';

        return $amount.' · Haiku';
    }

    /**
     * The row a worker is actually executing right now. A job that is merely waiting
     * in the queue is not running, however far along the story's status is.
     *
     * @param  array{step: string, label: string, done: int, total: int, stage: string|null, queued: bool}|null  $progress
     */
    private function runningRow(?array $progress): ?int
    {
        if ($progress === null || $progress['queued']) {
            return null;
        }

        return $this->progressRow($progress);
    }

    /**
     * @param  array{step: string, label: string, done: int, total: int, stage: string|null, queued: bool}|null  $progress
     */
    private function queuedRow(?array $progress): ?int
    {
        if ($progress === null || ! $progress['queued']) {
            return null;
        }

        return $this->progressRow($progress);
    }

    /**
     * @param  array{step: string, label: string, done: int, total: int, stage: string|null, queued: bool}  $progress
     */
    private function progressRow(array $progress): ?int
    {
        foreach (self::ROWS as $index => $row) {
            if ($row['job'] !== $progress['step']) {
                continue;
            }

            if ($row['stage'] === null || $row['stage'] === $progress['stage']) {
                return $index + 1;
            }

            if ($progress['stage'] === null && $row['stage'] === 'generate') {
                return 1;
            }

            if ($progress['stage'] === null && $row['stage'] === 'plan') {
                return 4;
            }
        }

        return null;
    }

    /**
     * @param  array{step: string, label: string, done: int, total: int, stage: string|null, queued: bool}|null  $progress
     */
    private function failedRow(Story $story, ?array $progress): ?int
    {
        if ($story->status !== StoryStatus::Failed) {
            return null;
        }

        if ($progress !== null && ($row = $this->progressRow($progress)) !== null) {
            return $row;
        }

        return match ($story->failed_step) {
            'script' => 1,
            'narration' => 3,
            'images' => 4,
            'sound' => 6,
            'render' => 7,
            default => null,
        };
    }

    private function jobsDone(Story $story): int
    {
        if ($story->status === StoryStatus::Failed) {
            return match ($story->failed_step) {
                'narration' => 1,
                'images' => 2,
                'sound' => 3,
                'render' => 4,
                default => 0,
            };
        }

        return match ($story->status) {
            StoryStatus::Draft => 0,
            StoryStatus::ScriptReady => 1,
            StoryStatus::Narrated => 2,
            StoryStatus::ImagesReady,
            StoryStatus::Mixed => 3,
            StoryStatus::Rendered,
            StoryStatus::PendingReview,
            StoryStatus::ReadyToPublish,
            StoryStatus::Downloaded,
            StoryStatus::Published => 5,
            default => 0,
        };
    }

    /**
     * @param  array{job: string, stage: string|null, name: string, noun: string}  $row
     */
    private function rowState(int $number, array $row, int $jobsDone, ?int $running, ?int $queued, ?int $failedRow): string
    {
        if ($failedRow === $number) {
            return 'fallido';
        }

        if ($running === $number) {
            return 'en curso';
        }

        // A queued re-run beats "hecho": the step is about to be redone.
        if ($queued === $number) {
            return 'en cola';
        }

        if ($this->rowComplete($row, $jobsDone, $running)) {
            return 'hecho';
        }

        return 'en espera';
    }

    /**
     * @param  array{job: string, stage: string|null, name: string, noun: string}  $row
     */
    private function rowComplete(array $row, int $jobsDone, ?int $running): bool
    {
        $jobRank = match ($row['job']) {
            'script' => 1,
            'narration' => 2,
            'images' => 3,
            'sound' => 4,
            'render' => 5,
            default => 99,
        };

        if ($jobsDone >= $jobRank) {
            return true;
        }

        if ($row['job'] === 'script' && $row['stage'] === 'generate' && $running === 2) {
            return true;
        }

        if ($row['job'] === 'images' && $row['stage'] === 'plan' && $running === 5) {
            return true;
        }

        return false;
    }

    private function currentRow(int $jobsDone, ?int $running, ?int $failedRow): int
    {
        if ($failedRow !== null) {
            return $failedRow;
        }

        if ($running !== null) {
            return $running;
        }

        return match ($jobsDone) {
            0 => 1,
            1 => 3,
            2 => 4,
            3 => 6,
            4 => 7,
            default => 7,
        };
    }

    /**
     * @param  array{step: string, label: string, done: int, total: int, stage: string|null, queued: bool}|null  $progress
     */
    private function rowProgress(string $state, ?array $progress, bool $isRunning): float
    {
        if ($state === 'hecho') {
            return 1.0;
        }

        if ($state === 'en espera') {
            return 0.0;
        }

        if (! $isRunning || $progress === null || $progress['total'] < 1) {
            return 0.0;
        }

        return max(0.0, min(1.0, $progress['done'] / $progress['total']));
    }

    /**
     * @param  array{step: string, label: string, done: int, total: int, stage: string|null, queued: bool}|null  $progress
     */
    private function rowUnit(
        int $number,
        string $state,
        Story $story,
        ?array $progress,
        string $noun,
        bool $isRunning,
    ): string {
        if ($state === 'en espera' || $state === 'en cola' || $state === 'fallido') {
            return '—';
        }

        if ($state === 'en curso' && $isRunning && $progress !== null) {
            return $progress['done'].' / '.$progress['total'].' '.$noun;
        }

        return match ($number) {
            1 => $story->scene_count === null ? '—' : $story->scene_count.' escenas',
            2 => $story->score === null ? '—' : $this->formatScore($story->score).' / 10',
            3 => $story->sentence_count === null ? '—' : $story->sentence_count.' frases',
            4 => $story->shot_count === null ? '—' : $story->shot_count.' planos',
            5 => $story->shot_count === null ? '—' : $story->shot_count.' imágenes',
            6 => $this->soundUnit($story),
            7 => $story->video_seconds === null ? '—' : $this->formatClock($story->video_seconds),
            default => '—',
        };
    }

    private function soundUnit(Story $story): string
    {
        if ($story->effect_count === null && $story->lufs === null) {
            return '—';
        }

        $effects = ($story->effect_count ?? 0).' efectos';
        $lufs = $story->lufs === null ? '—' : $this->formatScore($story->lufs).' LUFS';

        return $effects.' · '.$lufs;
    }

    /**
     * @param  array{job: string, stage: string|null, name: string, noun: string}  $row
     * @param  array<string, array{start: CarbonInterface, end: CarbonInterface|null}>  $windows
     */
    private function rowTime(array $row, string $state, array $windows, ?int $startedAt): string
    {
        // A step that has not begun has no clock of its own; showing the status
        // window would report the whole wait as if the step were working.
        if ($state === 'en espera' || $state === 'en cola') {
            return '—';
        }

        // The running step times the work, not the queue it waited in: those two
        // only agree while a worker is alive to pick the job up straight away.
        if ($startedAt !== null) {
            return $this->formatClock(now()->getTimestamp() - $startedAt);
        }

        $status = match ($row['job']) {
            'script' => StoryStatus::Draft->value,
            'narration' => StoryStatus::ScriptReady->value,
            'images' => StoryStatus::Narrated->value,
            'sound' => StoryStatus::ImagesReady->value,
            'render' => StoryStatus::Mixed->value,
            default => null,
        };

        if ($status === null || ! isset($windows[$status])) {
            return '—';
        }

        $start = $windows[$status]['start'];
        $end = $windows[$status]['end'] ?? now();

        return $this->formatClock($end->diffInSeconds($start));
    }

    /**
     * @return array<string, array{start: CarbonInterface, end: CarbonInterface|null}>
     */
    private function statusWindows(Story $story): array
    {
        $changes = $story->events
            ->filter(static fn (StoryEvent $event): bool => $event->type === 'status_changed')
            ->sortBy(fn (StoryEvent $event): int => $event->created_at?->getTimestamp() ?? $event->id)
            ->values();

        $created = $story->events
            ->filter(static fn (StoryEvent $event): bool => $event->type === 'created')
            ->sortBy(fn (StoryEvent $event): int => $event->created_at?->getTimestamp() ?? $event->id)
            ->first();

        $windows = [];
        $draftStart = $created?->created_at ?? $story->created_at;

        if ($draftStart instanceof CarbonInterface) {
            $windows[StoryStatus::Draft->value] = ['start' => $draftStart, 'end' => null];
        }

        foreach ($changes as $event) {
            $at = $event->created_at;

            if (! $at instanceof CarbonInterface) {
                continue;
            }

            $from = (string) $event->from_status;
            $to = (string) $event->to_status;

            if (isset($windows[$from]) && $windows[$from]['end'] === null) {
                $windows[$from]['end'] = $at;
            }

            if ($to !== StoryStatus::Failed->value) {
                $windows[$to] = ['start' => $at, 'end' => null];
            }
        }

        $current = $story->status->value;

        if ($current !== StoryStatus::Failed->value && isset($windows[$current])) {
            $windows[$current]['end'] = null;
        }

        return $windows;
    }

    private function elapsed(Story $story): string
    {
        $start = $this->firstEventAt($story) ?? $story->created_at;

        if (! $start instanceof CarbonInterface) {
            return '00:00';
        }

        return $this->formatClock(now()->diffInSeconds($start));
    }

    private function firstEventAt(Story $story): ?CarbonInterface
    {
        $oldest = $story->events
            ->sortBy(fn (StoryEvent $event): int => $event->created_at?->getTimestamp() ?? $event->id)
            ->first();

        return $oldest?->created_at;
    }

    private function title(Story $story): string
    {
        $title = trim((string) $story->title);

        return $title === '' ? 'Sin título' : $title;
    }

    private function formatScore(float $value): string
    {
        return rtrim(rtrim(sprintf('%.1f', $value), '0'), '.') ?: '0';
    }

    private function formatClock(float|int $seconds): string
    {
        $total = (int) floor(abs((float) $seconds));
        $minutes = intdiv($total, 60);

        if ($minutes < 60) {
            return sprintf('%02d:%02d', $minutes, $total % 60);
        }

        return sprintf('%d:%02d:%02d', intdiv($minutes, 60), $minutes % 60, $total % 60);
    }
}
