<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DataObjects\Story;
use App\DataObjects\StoryReview;
use App\Exceptions\InvalidStoryException;
use App\Services\Story\StoryGenerator;
use App\Services\Story\StoryPromptBuilder;
use App\Services\Story\StoryReviewer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class GenerateStoryCommand extends Command
{
    protected $signature = 'story:generate {--premise=} {--mode=} {--lore=} {--count=1} {--no-review} {--dry-run}';

    protected $description = 'Genera guiones de terror y los guarda en storage/app/stories';

    private const GENERATION_ATTEMPTS = 3;

    private readonly string $outputDirectory;

    private readonly bool $reviewEnabled;

    private readonly string $defaultMode;

    public function __construct(
        private StoryGenerator $generator,
        private StoryReviewer $reviewer,
        private StoryPromptBuilder $promptBuilder,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->reviewEnabled = (bool) $config->get('stories.review.enabled');
        $this->defaultMode = (string) $config->get('stories.story.default_mode');
    }

    public function handle(): int
    {
        $count = (int) $this->option('count');
        $premise = (string) ($this->option('premise') ?? '');
        $dryRun = (bool) $this->option('dry-run');
        $skipReview = (bool) $this->option('no-review');

        if ($count < 1) {
            $this->error('El recuento debe ser al menos 1.');

            return self::FAILURE;
        }

        $mode = $this->resolveMode();

        if ($mode === null) {
            return self::FAILURE;
        }

        $forcedLore = $this->resolveForcedLore($mode);

        if ($forcedLore === false) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Modo simulación: no se escribirán archivos.');
        }

        $succeeded = 0;
        $verdicts = [
            'publish' => 0,
            'revise' => 0,
            'discard' => 0,
        ];
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            $lore = $mode === 'folklore'
                ? ($forcedLore ?? $this->randomLore())
                : null;

            try {
                $story = $this->generateWithRetries($premise, $mode, $lore['slug'] ?? null);
                $review = $this->reviewStory($story, $skipReview);

                if (! $dryRun) {
                    $this->writeStory($story, $review);
                }

                $succeeded++;

                if ($review instanceof StoryReview && isset($verdicts[$review->verdict])) {
                    $verdicts[$review->verdict]++;
                }

                $bar->clear();
                $this->renderStoryTable($story, $review, $mode, $lore['name'] ?? null);
                $bar->display();
            } catch (Throwable $exception) {
                $bar->clear();
                $this->error($exception->getMessage());
                $bar->display();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->renderVerdictSummary($verdicts);

        if ($succeeded === 0) {
            $this->error('Han fallado todas las historias.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveMode(): ?string
    {
        $mode = strtolower(trim((string) ($this->option('mode') ?: $this->defaultMode)));

        if (! in_array($mode, ['folklore', 'original'], true)) {
            $this->error("El modo '{$mode}' no es válido. Usa folklore u original.");

            return null;
        }

        return $mode;
    }

    private function generateWithRetries(string $premise, string $mode, ?string $loreSlug): Story
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::GENERATION_ATTEMPTS; $attempt++) {
            try {
                return $this->generator->generate($premise, $mode, $loreSlug);
            } catch (InvalidStoryException $exception) {
                $lastException = $exception;

                if ($attempt < self::GENERATION_ATTEMPTS) {
                    $this->warn("Intento {$attempt} rechazado: {$exception->getMessage()} Reintentando...");
                }
            }
        }

        throw $lastException ?? new RuntimeException('No se pudo generar la historia.');
    }

    /**
     * @return array{slug: string, name: string, region: string, summary: string, motifs: list<string>}|false|null
     */
    private function resolveForcedLore(string $mode): array|false|null
    {
        $slug = trim((string) ($this->option('lore') ?? ''));

        if ($slug === '') {
            return null;
        }

        if ($mode !== 'folklore') {
            $this->error('La opción --lore solo se usa en modo folklore.');

            return false;
        }

        foreach ($this->promptBuilder->loreEntries() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        $this->error("No hay una ficha de folklore con el slug '{$slug}'.");
        $this->newLine();
        $this->info('Slugs disponibles:');

        foreach ($this->promptBuilder->loreEntries() as $entry) {
            $this->line("  {$entry['slug']}  {$entry['name']} ({$entry['region']})");
        }

        return false;
    }

    /**
     * @return array{slug: string, name: string, region: string, summary: string, motifs: list<string>}
     */
    private function randomLore(): array
    {
        $entries = $this->promptBuilder->loreEntries();

        return $entries[array_rand($entries)];
    }

    private function reviewStory(Story $story, bool $skipReview): ?StoryReview
    {
        if ($skipReview || ! $this->reviewEnabled) {
            return null;
        }

        return $this->reviewer->review($story);
    }

    private function writeStory(Story $story, ?StoryReview $review): void
    {
        $this->files->ensureDirectoryExists($this->outputDirectory);

        $filename = sprintf(
            '%s-%s.json',
            date('Y-m-d'),
            Str::slug($story->title),
        );

        $payload = $story->toArray();

        if ($review instanceof StoryReview) {
            $payload['review'] = $review->toArray();
        }

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar la historia a JSON.');
        }

        $this->files->put($this->outputDirectory.DIRECTORY_SEPARATOR.$filename, $json);
    }

    private function renderStoryTable(Story $story, ?StoryReview $review, string $mode, ?string $loreName): void
    {
        $seconds = $story->estimatedDurationSeconds();

        $this->table(
            ['Título', 'Escenas', 'Palabras', 'Duración', 'Modo', 'Folclore', 'Score', 'Verdict'],
            [[
                $story->title,
                (string) count($story->scenes),
                (string) $story->wordCount(),
                sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60),
                $mode,
                $loreName ?? '—',
                $review instanceof StoryReview ? (string) $review->score : '—',
                $review instanceof StoryReview ? $this->colorVerdict($review->verdict) : '—',
            ]],
        );
    }

    private function colorVerdict(string $verdict): string
    {
        return match ($verdict) {
            'publish' => "<fg=green>{$verdict}</>",
            'revise' => "<fg=yellow>{$verdict}</>",
            'discard' => "<fg=red>{$verdict}</>",
            default => $verdict,
        };
    }

    /**
     * @param  array{publish: int, revise: int, discard: int}  $verdicts
     */
    private function renderVerdictSummary(array $verdicts): void
    {
        $this->info('Reparto de veredictos:');
        $this->line('  <fg=green>publish</>: '.$verdicts['publish']);
        $this->line('  <fg=yellow>revise</>: '.$verdicts['revise']);
        $this->line('  <fg=red>discard</>: '.$verdicts['discard']);
    }
}
