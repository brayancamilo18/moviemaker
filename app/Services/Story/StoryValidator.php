<?php

declare(strict_types=1);

namespace App\Services\Story;

use App\DataObjects\DirectedSfx;
use App\DataObjects\Shot;
use App\Services\Audio\NarrationClock;
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

    /**
     * @var list<string>
     */
    private const FIGURE_SUBJECTS = ['protagonist', 'threat', 'both'];

    private readonly string $storiesDirectory;

    private readonly float $tailSeconds;

    public function __construct(
        private NarrationClock $clock,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->tailSeconds = (float) $config->get('stories.audio.tail_seconds', 10.0);
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
            $this->checkShotSum($context),
            $this->checkDescriptions($context),
            $this->checkPlannerVersion($context),
            $this->checkPlaceholders($context),
            $this->checkFigureRatio($context),
            $this->checkDetailRatio($context),
            $this->checkRevealTiming($context),
            $this->checkEffectsInShots($context),
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
     *     shotsError: ?string
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
            'directedSfx' => $this->loadDirectedSfx($directory.DIRECTORY_SEPARATOR.'sounds.json'),
        ];
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
        $label = 'Planos con figura ≥ 55%';

        if (is_string($context['shotsError'] ?? null) || $this->shots($context) === []) {
            return $this->warn('figure_ratio', $label, is_string($context['shotsError'] ?? null) ? (string) $context['shotsError'] : 'No hay planos.');
        }

        $shots = $this->shots($context);
        $figures = 0;

        foreach ($shots as $shot) {
            if (in_array($shot->subject, self::FIGURE_SUBJECTS, true)) {
                $figures++;
            }
        }

        $ratio = $figures / count($shots);
        $percent = (int) round($ratio * 100);
        $detail = sprintf('%d/%d planos con figura (%d%%).', $figures, count($shots), $percent);

        if ($ratio < 0.55) {
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

        $shots = $this->shots($context);
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
