<?php

declare(strict_types=1);

namespace App\Services\Story;

use App\DataObjects\DirectedSfx;
use App\DataObjects\NarrationWord;
use App\DataObjects\Shot;
use App\Services\Audio\NarrationClock;
use App\Services\Audio\SfxAnchor;
use App\Services\Audio\TranscriptTimer;
use App\Services\Image\ShotPlanner;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class StoryValidator
{
    private const MIX_TOLERANCE = 0.05;

    private const SHOT_SUM_TOLERANCE = 0.01;

    private const OUTRO_WORD_COVERAGE = 0.6;

    private const OUTRO_END_SLACK = 0.5;

    private readonly string $storiesDirectory;

    private readonly float $tailSeconds;

    private readonly float $maxShotDuration;

    private readonly float $threatRatioMax;

    private readonly bool $outroEnabled;

    private readonly int $outroSceneOrder;

    private readonly string $outroText;

    public function __construct(
        private NarrationClock $clock,
        private TranscriptTimer $timer,
        private SfxAnchor $anchor,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->tailSeconds = (float) $config->get('stories.audio.tail_seconds', 10.0);
        $this->maxShotDuration = (float) $config->get('stories.shots.max_duration')
            + (float) $config->get('stories.shots.max_hold_slack');
        $this->threatRatioMax = (float) $config->get('stories.images.direction.threat_ratio_max');
        $this->outroEnabled = (bool) $config->get('stories.story.outro.enabled');
        $this->outroSceneOrder = (int) $config->get('stories.story.outro.scene_order');
        $this->outroText = trim((string) $config->get('stories.story.outro.text'));
    }

    /**
     * @return array{
     *     passed: bool,
     *     checks: list<array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}>
     * }
     */
    public function validate(string $slug): array
    {
        $context = $this->context($slug);
        $checks = [
            $this->checkMixDuration($context),
            $this->checkTimingsCoverage($context),
            $this->checkShotSum($context),
            $this->checkShotDuration($context),
            $this->checkDescriptions($context),
            $this->checkPlannerVersion($context),
            $this->checkPlaceholders($context),
            $this->checkFigureRatio($context),
            $this->checkDetailRatio($context),
            $this->checkRevealTiming($context),
            $this->checkEffectsInShots($context),
            $this->checkEffectAnchors($context),
            $this->checkOutroPresent($context),
        ];

        $passed = true;

        foreach ($checks as $check) {
            if ($check['blocking'] && $check['status'] === 'fail') {
                $passed = false;
            }
        }

        return [
            'passed' => $passed,
            'checks' => $checks,
        ];
    }

    /**
     * La misma comprobación que cierra validate(), para las previas del render.
     *
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    public function outroCheck(string $slug): array
    {
        return $this->checkOutroPresent($this->context($slug));
    }

    /**
     * @return array{
     *     slug: string,
     *     directory: string,
     *     narration: string,
     *     mix: ?string,
     *     shotsPath: string,
     *     soundsPath: string,
     *     plannerVersion: ?int,
     *     shots: list<Shot>,
     *     placeholders: list<int>,
     *     directedSfx: list<DirectedSfx>,
     *     shotsError: ?string,
     *     timings: list<array{start: float, end: float, alignment: string, sceneOrder: int, text: string, words: list<NarrationWord>}>,
     *     narrationWords: list<NarrationWord>,
     *     timingsError: ?string
     * }
     */
    private function context(string $slug): array
    {
        $directory = $this->directory($slug);

        return [
            'slug' => $slug,
            'directory' => $directory,
            'narration' => $directory.DIRECTORY_SEPARATOR.'narration.wav',
            'mix' => $this->mixPath($directory),
            'shotsPath' => $directory.DIRECTORY_SEPARATOR.'shots.json',
            'soundsPath' => $directory.DIRECTORY_SEPARATOR.'sounds.json',
            ...$this->loadShots($directory.DIRECTORY_SEPARATOR.'shots.json'),
            ...$this->loadTimings($directory.DIRECTORY_SEPARATOR.'timings.json'),
            'directedSfx' => $this->loadDirectedSfx($directory.DIRECTORY_SEPARATOR.'sounds.json'),
        ];
    }

    /**
     * Protege a las historias ya narradas: si la alineación se fue por posición o dejó cola de
     * máster sin habla, todo lo que cuelga de timings.json va desplazado.
     *
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkTimingsCoverage(array $context): array
    {
        $label = 'timings.json ancla por texto y cubre el máster';

        if (is_string($context['timingsError'] ?? null)) {
            return $this->warn('timings_coverage', $label, (string) $context['timingsError']);
        }

        /** @var list<array{start: float, end: float, alignment: string}> $sentences */
        $sentences = is_array($context['timings'] ?? null) ? $context['timings'] : [];

        try {
            $report = $this->timer->alignmentReport($sentences, (string) $context['narration']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->warn('timings_coverage', $label, $exception->getMessage());
        }

        $problems = $this->timer->alignmentProblems($report);

        if ($problems !== []) {
            return $this->fail(
                'timings_coverage',
                $label,
                implode(' ', $problems).' Vuelve a alinear con story:narrate --timings-only.',
                true,
            );
        }

        return $this->ok('timings_coverage', $label, sprintf(
            '%d/%d frases por texto, %.3f s sin cubrir.',
            $report['textAligned'],
            $report['sentences'],
            $report['uncovered'],
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkShotDuration(array $context): array
    {
        $label = sprintf('Ningún plano pasa de %.1f s', $this->maxShotDuration);

        if (is_string($context['shotsError'] ?? null)) {
            return $this->fail('shot_duration', $label, (string) $context['shotsError'], true);
        }

        $long = [];

        foreach ($this->storyShots($context) as $shot) {
            $duration = $shot->end - $shot->start;

            if ($duration > $this->maxShotDuration + 0.0005) {
                $long[] = sprintf('#%d (%.3f s)', $shot->order, $duration);
            }
        }

        if ($long !== []) {
            return $this->fail(
                'shot_duration',
                $label,
                'Planos demasiado largos: '.implode(', ', $long).'. Regenera con story:images.',
                true,
            );
        }

        return $this->ok('shot_duration', $label, sprintf('%d plano(s) por debajo del techo.', count($this->shots($context))));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkMixDuration(array $context): array
    {
        $label = 'narration_mix.wav = NarrationClock + cola ±50 ms';
        $mix = $context['mix'];
        $narration = (string) $context['narration'];

        if (! is_string($mix) || $mix === '') {
            return $this->fail('mix_duration', $label, 'No hay narration_mix.wav. Ejecuta story:mix primero.', true);
        }

        try {
            $mixDuration = $this->clock->narrationEnd($mix);
            $expected = $this->clock->masterDuration($narration, $this->tailSeconds);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->fail('mix_duration', $label, $exception->getMessage(), true);
        }

        $delta = round($mixDuration - $expected, 3);

        if (abs($delta) > self::MIX_TOLERANCE) {
            return $this->fail(
                'mix_duration',
                $label,
                sprintf('El mix dura %.3f s y el máster previsto es %.3f s (desfase %+.3f s).', $mixDuration, $expected, $delta),
                true,
            );
        }

        return $this->ok('mix_duration', $label, sprintf('Mix %.3f s (previsto %.3f s).', $mixDuration, $expected));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkShotSum(array $context): array
    {
        $label = 'Suma de planos = duración del máster ±10 ms';

        if (is_string($context['shotsError'] ?? null)) {
            return $this->fail('shot_sum', $label, (string) $context['shotsError'], true);
        }

        try {
            $master = $this->clock->narrationEnd((string) $context['narration']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->fail('shot_sum', $label, $exception->getMessage(), true);
        }

        $sum = 0.0;

        foreach ($this->shots($context) as $shot) {
            $sum += max(0.0, $shot->end - $shot->start);
        }

        $sum = round($sum, 3);
        $delta = round($sum - $master, 3);

        if (abs($delta) > self::SHOT_SUM_TOLERANCE) {
            return $this->fail(
                'shot_sum',
                $label,
                sprintf('Los planos cubren %.3f s y el máster dura %.3f s (desfase %+.3f s).', $sum, $master, $delta),
                true,
            );
        }

        return $this->ok('shot_sum', $label, sprintf('Planos %.3f s (máster %.3f s).', $sum, $master));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkDescriptions(array $context): array
    {
        $label = 'Todos los planos tienen description';

        if (is_string($context['shotsError'] ?? null)) {
            return $this->fail('descriptions', $label, (string) $context['shotsError'], true);
        }

        $empty = [];

        foreach ($this->shots($context) as $shot) {
            if (trim($shot->description) === '') {
                $empty[] = $shot->order;
            }
        }

        if ($empty !== []) {
            return $this->fail(
                'descriptions',
                $label,
                'Sin description: #'.implode('  #', $empty).'.',
                true,
            );
        }

        return $this->ok('descriptions', $label, 'Todos los planos tienen description.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkPlannerVersion(array $context): array
    {
        $label = 'plannerVersion es '.ShotPlanner::VERSION;

        if (is_string($context['shotsError'] ?? null)) {
            return $this->fail('planner_version', $label, (string) $context['shotsError'], true);
        }

        $version = $context['plannerVersion'] ?? null;

        if (! is_int($version) || $version !== ShotPlanner::VERSION) {
            $seen = $version === null ? 'ausente' : (string) $version;

            return $this->fail(
                'planner_version',
                $label,
                sprintf('Plan de plannerVersion %s; el actual es %d. Regenera con story:images.', $seen, ShotPlanner::VERSION),
                true,
            );
        }

        return $this->ok('planner_version', $label, 'plannerVersion '.$version.'.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkPlaceholders(array $context): array
    {
        $label = 'Ninguna imagen es un marcador';

        if (is_string($context['shotsError'] ?? null)) {
            return $this->fail('placeholders', $label, (string) $context['shotsError'], true);
        }

        $placeholders = is_array($context['placeholders'] ?? null) ? $context['placeholders'] : [];

        if ($placeholders !== []) {
            return $this->fail(
                'placeholders',
                $label,
                'Marcadores: #'.implode('  #', $placeholders).'.',
                true,
            );
        }

        return $this->ok('placeholders', $label, 'Ningún plano es un marcador.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkFigureRatio(array $context): array
    {
        // La cámara es el oyente, así que la única figura posible es el ente y lo que hay que
        // vigilar es el techo, no el suelo: un ente en uno de cada tres planos deja de acechar, y
        // además son los planos que peor resuelve el proveedor gratuito.
        $ceiling = (int) round($this->threatRatioMax * 100);
        $label = "Planos con el ente ≤ {$ceiling}%";

        if (is_string($context['shotsError'] ?? null) || $this->shots($context) === []) {
            return $this->warn('figure_ratio', $label, is_string($context['shotsError'] ?? null) ? (string) $context['shotsError'] : 'No hay planos.');
        }

        $shots = $this->storyShots($context);

        if ($shots === []) {
            return $this->ok('figure_ratio', $label, 'Sin planos de historia que contar.');
        }

        $figures = 0;

        foreach ($shots as $shot) {
            if ($shot->subject === 'threat') {
                $figures++;
            }
        }

        $ratio = $figures / count($shots);
        $percent = (int) round($ratio * 100);
        $detail = sprintf('%d/%d planos con el ente (%d%%).', $figures, count($shots), $percent);

        if ($ratio > $this->threatRatioMax) {
            return $this->warn('figure_ratio', $label, $detail);
        }

        return $this->ok('figure_ratio', $label, $detail);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkDetailRatio(array $context): array
    {
        $label = 'Planos detail ≤ 25%';

        if (is_string($context['shotsError'] ?? null) || $this->shots($context) === []) {
            return $this->warn('detail_ratio', $label, is_string($context['shotsError'] ?? null) ? (string) $context['shotsError'] : 'No hay planos.');
        }

        $shots = $this->storyShots($context);

        if ($shots === []) {
            return $this->ok('detail_ratio', $label, 'Sin planos de historia que contar.');
        }

        $details = 0;

        foreach ($shots as $shot) {
            if ($shot->subject === 'detail') {
                $details++;
            }
        }

        $ratio = $details / count($shots);
        $percent = (int) round($ratio * 100);
        $detail = sprintf('%d/%d planos detail (%d%%).', $details, count($shots), $percent);

        if ($ratio > 0.25) {
            return $this->warn('detail_ratio', $label, $detail);
        }

        return $this->ok('detail_ratio', $label, $detail);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkRevealTiming(array $context): array
    {
        $label = 'Ningún threatStage reveal antes del 70%';

        if (is_string($context['shotsError'] ?? null) || $this->shots($context) === []) {
            return $this->warn('reveal_timing', $label, is_string($context['shotsError'] ?? null) ? (string) $context['shotsError'] : 'No hay planos.');
        }

        try {
            $duration = $this->clock->narrationEnd((string) $context['narration']);
        } catch (Throwable) {
            $duration = 0.0;

            foreach ($this->shots($context) as $shot) {
                $duration = max($duration, $shot->end);
            }
        }

        if ($duration <= 0.0) {
            return $this->warn('reveal_timing', $label, 'No hay duración de máster para situar el reveal.');
        }

        $threshold = round($duration * 0.70, 3);
        $early = [];

        foreach ($this->shots($context) as $shot) {
            if ($shot->threatStage === 'reveal' && $shot->start < $threshold) {
                $early[] = sprintf('#%d (%.3f s)', $shot->order, $shot->start);
            }
        }

        if ($early !== []) {
            return $this->warn(
                'reveal_timing',
                $label,
                'Reveal antes de '.sprintf('%.3f', $threshold).' s: '.implode(', ', $early).'.',
            );
        }

        return $this->ok('reveal_timing', $label, 'Ningún reveal antes del 70% ('.sprintf('%.3f', $threshold).' s).');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkEffectsInShots(array $context): array
    {
        $label = 'Todo efecto cae dentro de su plano';

        if (is_string($context['shotsError'] ?? null)) {
            return $this->fail('effects_in_shots', $label, (string) $context['shotsError'], true);
        }

        $byOrder = [];

        foreach ($this->shots($context) as $shot) {
            $byOrder[$shot->order] = $shot;
        }

        $outside = [];

        foreach ($this->effects($context) as $effect) {
            $shot = $byOrder[$effect->shotIndex] ?? null;

            if (! $shot instanceof Shot) {
                $outside[] = sprintf('sfx plano %d (inexistente)', $effect->shotIndex);

                continue;
            }

            $span = $shot->end - $shot->start;
            $at = round($shot->start + $effect->offsetRatio * $span, 3);

            if ($at < $shot->start - 0.0005 || $at > $shot->end + 0.0005) {
                $outside[] = sprintf(
                    'sfx plano %d @ %.3f s (%.3f–%.3f)',
                    $effect->shotIndex,
                    $at,
                    $shot->start,
                    $shot->end,
                );
            }
        }

        if ($outside !== []) {
            return $this->fail('effects_in_shots', $label, implode('; ', $outside).'.', true);
        }

        $count = count($this->effects($context));

        return $this->ok(
            'effects_in_shots',
            $label,
            $count === 0 ? 'No hay efectos dirigidos.' : $count.' efecto(s) dentro de su plano.',
        );
    }

    /**
     * Cuántos golpes van a sonar de verdad. Un efecto cuya palabra no está alineada no se coloca, así
     * que sin este recuento la historia pierde sonidos y el informe de la mezcla es el único sitio
     * donde se nota. Es aviso y no bloqueante: quedarse sin un golpe no rompe el vídeo.
     *
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkEffectAnchors(array $context): array
    {
        $label = 'Todo efecto cuelga de su palabra en la narración';
        $effects = $this->effects($context);

        if ($effects === []) {
            return $this->ok('effect_anchors', $label, 'No hay efectos dirigidos.');
        }

        if (is_string($context['shotsError'] ?? null)) {
            return $this->warn('effect_anchors', $label, (string) $context['shotsError']);
        }

        if (is_string($context['timingsError'] ?? null)) {
            return $this->warn('effect_anchors', $label, (string) $context['timingsError']);
        }

        $byOrder = [];

        foreach ($this->shots($context) as $shot) {
            $byOrder[$shot->order] = $shot;
        }

        $words = $this->narrationWords($context);
        $anchored = 0;
        $lost = [];

        foreach ($effects as $effect) {
            $shot = $byOrder[$effect->shotIndex] ?? null;

            // El plano inexistente ya lo denuncia checkEffectsInShots; aquí no se cuenta dos veces.
            if (! $shot instanceof Shot) {
                continue;
            }

            if ($this->anchor->resolve($shot, $effect, $words) !== null) {
                $anchored++;

                continue;
            }

            $lost[] = sprintf(
                'plano %d «%s»',
                $effect->shotIndex,
                $effect->anchorWord === '' ? 'sin ancla' : $effect->anchorWord,
            );
        }

        $detail = sprintf('%d de %d efecto(s) anclados.', $anchored, count($effects));

        if ($lost !== []) {
            return $this->warn(
                'effect_anchors',
                $label,
                $detail.' No van a sonar: '.implode('; ', $lost).'.',
            );
        }

        return $this->ok('effect_anchors', $label, $detail);
    }

    /**
     * El outro no es un recorte: o está en el audio y en un solo plano, o el vídeo se publica
     * sin despedida y nadie se entera. Desactivarlo sigue siendo posible, pero deja un aviso.
     *
     * @param  array<string, mixed>  $context
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function checkOutroPresent(array $context): array
    {
        $label = 'El outro del canal está en el audio y en un solo plano';

        if (! $this->outroEnabled) {
            return $this->warn(
                'outro_present',
                $label,
                'El outro está desactivado. El vídeo se publicará sin despedida.',
            );
        }

        if (is_string($context['timingsError'] ?? null)) {
            return $this->fail(
                'outro_present',
                $label,
                'El outro no llegó al audio. '.(string) $context['timingsError'],
                true,
            );
        }

        $sentences = $this->outroSentences($context);

        if ($sentences === []) {
            return $this->fail(
                'outro_present',
                $label,
                sprintf(
                    'El outro no llegó al audio: timings.json no tiene frases de la escena %d.',
                    $this->outroSceneOrder,
                ),
                true,
            );
        }

        $expected = $this->tokens($this->outroText);
        $heard = $this->outroHeardTokens($sentences);
        $covered = $this->coveredWordCount($expected, $heard);
        $expectedCount = count($expected);
        $ratio = $expectedCount === 0 ? 1.0 : $covered / $expectedCount;

        if ($ratio < self::OUTRO_WORD_COVERAGE) {
            return $this->fail(
                'outro_present',
                $label,
                sprintf(
                    'El outro se sintetizó a medias: %d/%d palabras (%.0f%%).',
                    $covered,
                    $expectedCount,
                    $ratio * 100,
                ),
                true,
            );
        }

        $lastEnd = 0.0;

        foreach ($sentences as $sentence) {
            $lastEnd = max($lastEnd, $sentence['end']);
        }

        try {
            $narrationEnd = $this->clock->narrationEnd((string) $context['narration']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->fail('outro_present', $label, $exception->getMessage(), true);
        }

        $gap = round($narrationEnd - $lastEnd, 3);
        $allowed = $this->tailSeconds + self::OUTRO_END_SLACK;

        if ($gap > $allowed + 0.0005) {
            return $this->fail(
                'outro_present',
                $label,
                sprintf(
                    'Hay algo después del outro: acaba en %.3f s y el máster en %.3f s (hueco %.3f s; máximo %.3f s).',
                    $lastEnd,
                    $narrationEnd,
                    $gap,
                    $allowed,
                ),
                true,
            );
        }

        if (is_string($context['shotsError'] ?? null)) {
            return $this->fail('outro_present', $label, (string) $context['shotsError'], true);
        }

        $outroShots = [];

        foreach ($this->shots($context) as $shot) {
            if ($shot->isOutro) {
                $outroShots[] = $shot;
            }
        }

        if (count($outroShots) !== 1) {
            return $this->fail(
                'outro_present',
                $label,
                sprintf('Debe haber exactamente un plano de cierre; hay %d.', count($outroShots)),
                true,
            );
        }

        $firstStart = $sentences[0]['start'];

        foreach ($sentences as $sentence) {
            $firstStart = min($firstStart, $sentence['start']);
        }

        $shot = $outroShots[0];

        if ($shot->start > $firstStart + 0.0005 || $shot->end + 0.0005 < $lastEnd) {
            return $this->fail(
                'outro_present',
                $label,
                sprintf(
                    'El plano de cierre #%d (%.3f–%.3f) no cubre el outro (%.3f–%.3f).',
                    $shot->order,
                    $shot->start,
                    $shot->end,
                    $firstStart,
                    $lastEnd,
                ),
                true,
            );
        }

        return $this->ok(
            'outro_present',
            $label,
            sprintf(
                'Escena %d: %d frases, %d/%d palabras, plano #%d.',
                $this->outroSceneOrder,
                count($sentences),
                $covered,
                $expectedCount,
                $shot->order,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array{start: float, end: float, alignment: string, sceneOrder: int, text: string, words: list<NarrationWord>}>
     */
    private function outroSentences(array $context): array
    {
        $found = [];

        foreach ($this->timingSentences($context) as $sentence) {
            if ($sentence['sceneOrder'] === $this->outroSceneOrder) {
                $found[] = $sentence;
            }
        }

        return $found;
    }

    /**
     * @param  list<array{words: list<NarrationWord>}>  $sentences
     * @return list<string>
     */
    private function outroHeardTokens(array $sentences): array
    {
        $tokens = [];

        foreach ($sentences as $sentence) {
            foreach ($sentence['words'] as $word) {
                $token = $word->token;

                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $heard
     */
    private function coveredWordCount(array $expected, array $heard): int
    {
        $bag = [];

        foreach ($heard as $token) {
            $bag[$token] = ($bag[$token] ?? 0) + 1;
        }

        $covered = 0;

        foreach ($expected as $token) {
            if (($bag[$token] ?? 0) < 1) {
                continue;
            }

            $covered++;
            $bag[$token]--;
        }

        return $covered;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $normalized = mb_strtolower($text);
        $normalized = str_replace(["'", '’', '‘'], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return [];
        }

        return explode(' ', $normalized);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array{start: float, end: float, alignment: string, sceneOrder: int, text: string, words: list<NarrationWord>}>
     */
    private function timingSentences(array $context): array
    {
        /** @var list<array{start: float, end: float, alignment: string, sceneOrder: int, text: string, words: list<NarrationWord>}> $sentences */
        $sentences = is_array($context['timings'] ?? null) ? $context['timings'] : [];

        return $sentences;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<NarrationWord>
     */
    private function narrationWords(array $context): array
    {
        /** @var list<NarrationWord> $words */
        $words = is_array($context['narrationWords'] ?? null) ? $context['narrationWords'] : [];

        return $words;
    }

    /**
     * @return array{plannerVersion: ?int, shots: list<Shot>, placeholders: list<int>, shotsError: ?string}
     */
    private function loadShots(string $path): array
    {
        if (! $this->files->isFile($path)) {
            return [
                'plannerVersion' => null,
                'shots' => [],
                'placeholders' => [],
                'shotsError' => 'No hay shots.json. Ejecuta story:images primero.',
            ];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'plannerVersion' => null,
                'shots' => [],
                'placeholders' => [],
                'shotsError' => 'shots.json no es un JSON válido.',
            ];
        }

        if (! isset($decoded['shots']) || ! is_array($decoded['shots'])) {
            return [
                'plannerVersion' => array_key_exists('plannerVersion', $decoded) ? (int) $decoded['plannerVersion'] : null,
                'shots' => [],
                'placeholders' => [],
                'shotsError' => 'shots.json no tiene el esquema esperado.',
            ];
        }

        $shots = [];
        $placeholders = [];
        $plannerVersion = array_key_exists('plannerVersion', $decoded)
            ? (int) $decoded['plannerVersion']
            : null;

        foreach ($decoded['shots'] as $row) {
            if (! is_array($row) || ! isset($row['order'], $row['sceneOrder'])) {
                continue;
            }

            $shot = Shot::fromArray($row);
            $shots[] = $shot;

            $imagePath = $shot->imagePath ?? '';
            $placeholder = (bool) ($row['placeholder'] ?? false);

            if ($imagePath !== '' && str_starts_with(basename($imagePath), 'placeholder-')) {
                $placeholder = true;
            }

            if ($placeholder) {
                $placeholders[] = $shot->order;
            }
        }

        if ($shots === []) {
            return [
                'plannerVersion' => $plannerVersion,
                'shots' => [],
                'placeholders' => [],
                'shotsError' => 'shots.json no contiene planos.',
            ];
        }

        return [
            'plannerVersion' => $plannerVersion,
            'shots' => $shots,
            'placeholders' => $placeholders,
            'shotsError' => null,
        ];
    }

    /**
     * @return array{timings: list<array{start: float, end: float, alignment: string, sceneOrder: int, text: string, words: list<NarrationWord>}>, narrationWords: list<NarrationWord>, timingsError: ?string}
     */
    private function loadTimings(string $path): array
    {
        if (! $this->files->isFile($path)) {
            return [
                'timings' => [],
                'narrationWords' => [],
                'timingsError' => 'No hay timings.json. Ejecuta story:narrate primero.',
            ];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'timings' => [],
                'narrationWords' => [],
                'timingsError' => 'timings.json no es un JSON válido.',
            ];
        }

        if (! isset($decoded['sentences']) || ! is_array($decoded['sentences'])) {
            return [
                'timings' => [],
                'narrationWords' => [],
                'timingsError' => 'timings.json no tiene el esquema esperado.',
            ];
        }

        $sentences = [];
        $words = [];

        foreach ($decoded['sentences'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sentenceWords = [];

            foreach (is_array($row['words'] ?? null) ? $row['words'] : [] as $word) {
                if (is_array($word)) {
                    $sentenceWords[] = NarrationWord::fromArray($word);
                }
            }

            $sentences[] = [
                'start' => (float) ($row['start'] ?? 0),
                'end' => (float) ($row['end'] ?? 0),
                'alignment' => (string) ($row['alignment'] ?? ''),
                'sceneOrder' => (int) ($row['sceneOrder'] ?? 1),
                'text' => trim((string) ($row['text'] ?? '')),
                'words' => $sentenceWords,
            ];

            foreach ($sentenceWords as $word) {
                $words[] = $word;
            }
        }

        if ($sentences === []) {
            return [
                'timings' => [],
                'narrationWords' => [],
                'timingsError' => 'timings.json no contiene frases.',
            ];
        }

        usort(
            $words,
            static fn (NarrationWord $left, NarrationWord $right): int => $left->start <=> $right->start,
        );

        return [
            'timings' => $sentences,
            'narrationWords' => $words,
            'timingsError' => null,
        ];
    }

    /**
     * @return list<DirectedSfx>
     */
    private function loadDirectedSfx(string $path): array
    {
        if (! $this->files->isFile($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $effects = [];

        foreach (is_array($decoded['directedSfx'] ?? null) ? $decoded['directedSfx'] : [] as $row) {
            if (is_array($row)) {
                $effects[] = DirectedSfx::fromArray($row);
            }
        }

        return $effects;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<Shot>
     */
    private function shots(array $context): array
    {
        /** @var list<Shot> $shots */
        $shots = is_array($context['shots'] ?? null) ? $context['shots'] : [];

        return $shots;
    }

    /**
     * Planos de la historia, sin el cierre fijo del canal.
     *
     * @param  array<string, mixed>  $context
     * @return list<Shot>
     */
    private function storyShots(array $context): array
    {
        return array_values(array_filter(
            $this->shots($context),
            static fn (Shot $shot): bool => ! $shot->isOutro,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<DirectedSfx>
     */
    private function effects(array $context): array
    {
        /** @var list<DirectedSfx> $effects */
        $effects = is_array($context['directedSfx'] ?? null) ? $context['directedSfx'] : [];

        return $effects;
    }

    private function mixPath(string $directory): ?string
    {
        foreach (['narration_mix.wav', 'narration_mix.mp3'] as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;

            if ($this->files->isFile($path) && $this->files->size($path) > 0) {
                return $path;
            }
        }

        return null;
    }

    private function directory(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '' || basename($slug) !== $slug) {
            throw new InvalidArgumentException('El slug de la historia no es válido.');
        }

        return $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
    }

    /**
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function ok(string $id, string $label, string $detail): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => 'ok',
            'detail' => $detail,
            'blocking' => false,
        ];
    }

    /**
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function fail(string $id, string $label, string $detail, bool $blocking): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => 'fail',
            'detail' => $detail,
            'blocking' => $blocking,
        ];
    }

    /**
     * @return array{id: string, label: string, status: 'ok'|'fail'|'warn', detail: string, blocking: bool}
     */
    private function warn(string $id, string $label, string $detail): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => 'warn',
            'detail' => $detail,
            'blocking' => false,
        ];
    }
}
