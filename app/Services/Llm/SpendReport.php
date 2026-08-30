<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\Story;
use App\Models\StoryEvent;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class SpendReport
{
    /**
     * @var array<string, string>
     */
    private const STEP_LABELS = [
        'script' => 'guion',
        'narration' => 'narración',
        'images' => 'imágenes',
        'sound' => 'sonido',
        'render' => 'render',
    ];

    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @return array{euro: string, usd: float, note: string, title: string, calls: int, inputTokens: int, outputTokens: int, usedFallback: bool}
     */
    public function forMonth(?CarbonInterface $at = null): array
    {
        $month = $at ?? now();
        $stories = $this->storiesIn($month->copy()->startOfMonth(), $month->copy()->endOfMonth());

        $usd = (float) $stories->sum(static fn (Story $story): float => (float) $story->llm_cost_usd);
        $inputTokens = (int) $stories->sum(static fn (Story $story): int => (int) $story->llm_input_tokens);
        $outputTokens = (int) $stories->sum(static fn (Story $story): int => (int) $story->llm_output_tokens);
        $usedFallback = $stories->contains(static fn (Story $story): bool => (bool) $story->used_fallback);
        $calls = $this->callsIn($stories);

        return [
            'euro' => $this->formatEuro($usd),
            'usd' => $usd,
            'note' => $usedFallback ? 'respaldo Claude Haiku' : 'sin respaldo',
            'title' => sprintf(
                '%d llamadas · %s tokens de entrada · %s de salida · %s',
                $calls,
                number_format($inputTokens, 0, ',', '.'),
                number_format($outputTokens, 0, ',', '.'),
                $this->formatUsd($usd),
            ),
            'calls' => $calls,
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'usedFallback' => $usedFallback,
        ];
    }

    /**
     * @return array{month: string, rows: list<array{title: string, slug: string, step: string, calls: int, inputTokens: int, outputTokens: int, costUsd: float, costEur: float}>, totals: array{calls: int, inputTokens: int, outputTokens: int, costUsd: float, costEur: float, euro: string, usd: string}}
     */
    public function breakdown(?string $month = null): array
    {
        $at = $this->parseMonth($month);
        $stories = $this->storiesIn($at->copy()->startOfMonth(), $at->copy()->endOfMonth());
        $rows = [];

        foreach ($stories as $story) {
            $events = $story->events
                ->filter(static fn (StoryEvent $event): bool => $event->type === 'llm_usage')
                ->values();

            if ($events->isEmpty()) {
                if ((float) $story->llm_cost_usd <= 0.0 && (int) $story->llm_input_tokens < 1) {
                    continue;
                }

                $rows[] = $this->row(
                    $story,
                    '—',
                    0,
                    (int) $story->llm_input_tokens,
                    (int) $story->llm_output_tokens,
                    (float) $story->llm_cost_usd,
                );

                continue;
            }

            foreach ($events as $event) {
                $payload = is_array($event->payload) ? $event->payload : [];
                $rows[] = $this->row(
                    $story,
                    $this->stepLabel((string) ($payload['step'] ?? '—')),
                    (int) ($payload['calls'] ?? 0),
                    (int) ($payload['inputTokens'] ?? 0),
                    (int) ($payload['outputTokens'] ?? 0),
                    (float) ($payload['costUsd'] ?? 0),
                );
            }
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => $right['costUsd'] <=> $left['costUsd'],
        );

        $totals = [
            'calls' => 0,
            'inputTokens' => 0,
            'outputTokens' => 0,
            'costUsd' => 0.0,
            'costEur' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals['calls'] += $row['calls'];
            $totals['inputTokens'] += $row['inputTokens'];
            $totals['outputTokens'] += $row['outputTokens'];
            $totals['costUsd'] += $row['costUsd'];
            $totals['costEur'] += $row['costEur'];
        }

        return [
            'month' => $at->format('Y-m'),
            'rows' => $rows,
            'totals' => [
                ...$totals,
                'euro' => $this->formatEuro($totals['costUsd']),
                'usd' => $this->formatUsd($totals['costUsd']),
            ],
        ];
    }

    public function formatEuro(float $usd): string
    {
        return number_format($this->toEur($usd), 2, ',', '').' €';
    }

    public function formatUsd(float $usd): string
    {
        return number_format($usd, 2, ',', '').' $';
    }

    public function stepLabel(string $step): string
    {
        return self::STEP_LABELS[$step] ?? $step;
    }

    private function toEur(float $usd): float
    {
        return $usd * (float) $this->config->get('stories.llm.usd_to_eur');
    }

    /**
     * @return Collection<int, Story>
     */
    private function storiesIn(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return Story::query()
            ->with('events')
            ->whereBetween('created_at', [$start, $end])
            ->get();
    }

    /**
     * @param  Collection<int, Story>  $stories
     */
    private function callsIn(Collection $stories): int
    {
        $calls = 0;

        foreach ($stories as $story) {
            foreach ($story->events as $event) {
                if ($event->type !== 'llm_usage' || ! is_array($event->payload)) {
                    continue;
                }

                $calls += (int) ($event->payload['calls'] ?? 0);
            }
        }

        return $calls;
    }

    /**
     * @return array{title: string, slug: string, step: string, calls: int, inputTokens: int, outputTokens: int, costUsd: float, costEur: float}
     */
    private function row(
        Story $story,
        string $step,
        int $calls,
        int $inputTokens,
        int $outputTokens,
        float $costUsd,
    ): array {
        return [
            'title' => $story->title !== '' ? $story->title : $story->slug,
            'slug' => $story->slug,
            'step' => $step,
            'calls' => $calls,
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'costUsd' => $costUsd,
            'costEur' => $this->toEur($costUsd),
        ];
    }

    private function parseMonth(?string $month): CarbonInterface
    {
        if ($month === null || $month === '') {
            return now()->copy()->startOfMonth();
        }

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new InvalidArgumentException("El mes '{$month}' no es válido. Usa YYYY-MM.");
        }

        $parsed = Carbon::createFromFormat('!Y-m', $month);

        if ($parsed === false) {
            throw new InvalidArgumentException("El mes '{$month}' no es válido. Usa YYYY-MM.");
        }

        return $parsed->startOfMonth();
    }
}
