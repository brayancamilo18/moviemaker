<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\JsonLlm;
use App\DataObjects\Story as StoryScript;
use App\DataObjects\StoryReview;
use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Pipeline\ScriptStep;
use App\Services\Story\StoryPromptBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;

final class GenerateStoryCommand extends Command
{
    protected $signature = 'story:generate {--premise=} {--mode=} {--lore=} {--count=1} {--no-review} {--dry-run}';

    protected $description = 'Genera guiones de terror y los guarda en storage/app/stories';

    private readonly string $defaultMode;

    public function __construct(
        private ScriptStep $script,
        private StoryPromptBuilder $promptBuilder,
        private JsonLlm $llm,
        Repository $config,
    ) {
        parent::__construct();

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

            $story = new Story([
                'slug' => '',
                'title' => '',
                'mode' => $mode === 'original' ? StoryMode::Original : StoryMode::Folklore,
                'lore_slug' => $lore['slug'] ?? null,
                'lore_name' => $lore['name'] ?? null,
                'premise' => $premise !== '' ? $premise : null,
                'status' => StoryStatus::Draft,
            ]);

            try {
                $result = $this->script->run($story, null, [
                    'skip_review' => $skipReview,
                    'dry_run' => $dryRun,
                ]);
            } catch (Throwable $exception) {
                $bar->clear();
                $this->error($exception->getMessage());
                $bar->display();
                $bar->advance();

                continue;
            }

            $bar->clear();

            foreach ($result['warnings'] ?? [] as $warning) {
                $this->warn((string) $warning);
            }

            if (($result['ok'] ?? true) === false) {
                $this->error((string) ($result['error'] ?? 'No se pudo generar la historia.'));
                $bar->display();
                $bar->advance();

                continue;
            }

            $succeeded++;
            $verdict = $result['verdict'] ?? null;

            if (is_string($verdict) && isset($verdicts[$verdict])) {
                $verdicts[$verdict]++;
            }

            $this->renderStoryTable(
                $result['story'],
                $result['review'] instanceof StoryReview ? $result['review'] : null,
                $mode,
                $lore['name'] ?? null,
            );
            $bar->display();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($succeeded > 0) {
            $this->line('Escrito con '.$this->llm->name().'.');
        }

        $notice = $this->llm->fallbackNotice();

        if ($notice !== null) {
            $this->warn($notice);
        }

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

    private function renderStoryTable(StoryScript $story, ?StoryReview $review, string $mode, ?string $loreName): void
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
