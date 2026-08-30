<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Contracts\TextToSpeech;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\SoundCategorizer;
use App\Services\Tts\KokoroTts;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class StepPreflight
{
    /**
     * @var list<string>
     */
    public const STEPS = ['narration', 'images', 'sound', 'render'];

    /**
     * @var list<string>
     */
    private const IMAGE_PROVIDERS = ['pollinations'];

    /**
     * @var list<string>
     */
    private const RENDER_FILTERS = ['zoompan', 'xfade', 'loudnorm'];

    public function __construct(
        private Repository $config,
        private Filesystem $files,
        private TextToSpeech $tts,
        private ExecutableFinder $finder,
        private SoundCategorizer $categorizer,
        private AudioLibrary $library,
    ) {}

    /**
     * @return list<array{name: string, ok: bool, detail: string, fix: string}>
     */
    public function check(string $step): array
    {
        return match ($step) {
            'narration' => $this->narration(),
            'images' => $this->images(),
            'sound' => $this->sound(),
            'render' => $this->render(),
            default => [],
        };
    }

    /**
     * @return array{step: string|null, checks: list<array{name: string, ok: bool, detail: string, fix: string}>}
     */
    public function forStory(Story $story): array
    {
        $step = $this->nextStep($story);

        return [
            'step' => $step,
            'checks' => $step === null ? [] : $this->check($step),
        ];
    }

    public function nextStep(Story $story): ?string
    {
        if ($story->status === StoryStatus::Failed) {
            $failed = (string) $story->failed_step;

            return in_array($failed, self::STEPS, true) ? $failed : null;
        }

        return match ($story->status) {
            StoryStatus::ScriptReady => 'narration',
            StoryStatus::Narrated => 'images',
            StoryStatus::ImagesReady => 'sound',
            StoryStatus::Mixed => 'render',
            default => null,
        };
    }

    /**
     * @return array<string, list<array{name: string, ok: bool, detail: string, fix: string}>>
     */
    public function all(): array
    {
        $grouped = [];

        foreach (self::STEPS as $step) {
            $grouped[$step] = $this->check($step);
        }

        return $grouped;
    }

    /**
     * @return list<array{name: string, ok: bool, detail: string, fix: string}>
     */
    private function narration(): array
    {
        return [
            $this->sidecar(),
            $this->whisperBinary(),
            $this->onPath('ffmpeg', (string) $this->config->get('stories.ffmpeg.binary'), 'Sin él no hay ensamblado del máster.'),
            $this->onPath('ffprobe', (string) $this->config->get('stories.ffmpeg.ffprobe'), 'Sin él NarrationClock no puede medir el máster.'),
        ];
    }

    /**
     * @return list<array{name: string, ok: bool, detail: string, fix: string}>
     */
    private function images(): array
    {
        return [
            $this->imageProvider(),
            $this->imageCache(),
        ];
    }

    /**
     * @return list<array{name: string, ok: bool, detail: string, fix: string}>
     */
    private function sound(): array
    {
        return [
            $this->freesoundToken(),
            $this->coreKit(),
            $this->onPath('ffmpeg', (string) $this->config->get('stories.ffmpeg.binary'), 'Sin él no hay mezcla.'),
        ];
    }

    /**
     * @return list<array{name: string, ok: bool, detail: string, fix: string}>
     */
    private function render(): array
    {
        return [
            $this->ffmpegFilters(),
            $this->freeDisk(),
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function sidecar(): array
    {
        try {
            $available = $this->tts->isAvailable();
        } catch (Throwable $exception) {
            return $this->item(
                'sidecar de Kokoro',
                false,
                'No responde: '.$exception->getMessage(),
                KokoroTts::START_COMMAND,
            );
        }

        if (! $available) {
            return $this->item(
                'sidecar de Kokoro',
                false,
                'No responde en GET /health.',
                KokoroTts::START_COMMAND,
            );
        }

        return $this->item('sidecar de Kokoro', true, 'Responde GET /health con el modelo cargado.');
    }

    /**
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function whisperBinary(): array
    {
        $binary = (string) $this->config->get('stories.whisper.binary');

        if ($binary === '') {
            return $this->item(
                'binario de whisper.cpp',
                false,
                'WHISPER_BINARY está vacío.',
                'Instala whisper.cpp y define WHISPER_BINARY con la ruta al binario, o déjalo como whisper-cli en el PATH.',
            );
        }

        $path = $this->locate($binary);

        if ($path === null) {
            return $this->item(
                'binario de whisper.cpp',
                false,
                sprintf("No está en disco ni en el PATH: '%s'.", $binary),
                'Instala whisper.cpp y deja el binario en el PATH, o apunta WHISPER_BINARY a la ruta absoluta.',
            );
        }

        return $this->item('binario de whisper.cpp', true, $path);
    }

    /**
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function onPath(string $name, string $binary, string $consequence): array
    {
        if ($binary === '') {
            return $this->item(
                $name,
                false,
                'No hay binario configurado. '.$consequence,
                'Instala '.$name.' y déjalo en el PATH.',
            );
        }

        $path = $this->locate($binary);

        if ($path === null) {
            return $this->item(
                $name,
                false,
                sprintf("No está en el PATH: '%s'. %s", $binary, $consequence),
                'Instala '.$name.' y déjalo en el PATH.',
            );
        }

        return $this->item($name, true, $path);
    }

    /**
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function imageProvider(): array
    {
        $provider = strtolower(trim((string) $this->config->get('stories.images.provider')));

        if ($provider === '' || ! in_array($provider, self::IMAGE_PROVIDERS, true)) {
            return $this->item(
                'proveedor de imágenes',
                false,
                $provider === ''
                    ? 'IMAGE_PROVIDER no está definido.'
                    : "IMAGE_PROVIDER='{$provider}' no es un proveedor conocido.",
                'Define IMAGE_PROVIDER=pollinations en el .env y ejecuta php artisan config:clear.',
            );
        }

        $baseUrl = trim((string) $this->config->get('stories.images.pollinations.base_url'));

        if ($baseUrl === '') {
            return $this->item(
                'proveedor de imágenes',
                false,
                'pollinations está elegido, pero no tiene URL base.',
                'Revisa stories.images.pollinations.base_url y ejecuta php artisan config:clear.',
            );
        }

        return $this->item('proveedor de imágenes', true, $provider.' ('.$baseUrl.')');
    }

    /**
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function imageCache(): array
    {
        $relative = (string) $this->config->get('stories.images.cache_path', 'image-cache');
        $directory = storage_path('app/'.$relative);

        if (! $this->files->isDirectory($directory)) {
            return $this->item(
                'caché de imágenes',
                false,
                'No existe '.$directory.'.',
                'mkdir -p '.escapeshellarg($directory),
            );
        }

        if (! $this->files->isWritable($directory)) {
            return $this->item(
                'caché de imágenes',
                false,
                'Existe pero no se puede escribir: '.$directory.'.',
                'chmod u+w '.escapeshellarg($directory),
            );
        }

        return $this->item('caché de imágenes', true, $directory);
    }

    /**
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function freesoundToken(): array
    {
        $token = trim((string) $this->config->get('stories.audio.freesound.token'));

        if ($token === '') {
            return $this->item(
                'FREESOUND_TOKEN',
                false,
                'ausente. Sin él no se resuelven clips fuera del core kit.',
                'Añade FREESOUND_TOKEN al .env y ejecuta php artisan config:clear.',
            );
        }

        return $this->item('FREESOUND_TOKEN', true, 'definido');
    }

    /**
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function coreKit(): array
    {
        try {
            $categories = $this->categorizer->all();
        } catch (Throwable $exception) {
            return $this->item(
                'core kit',
                false,
                'No se pudieron leer las categorías: '.$exception->getMessage(),
                'php artisan audio:core-kit',
            );
        }

        $missing = [];

        foreach ($categories as $category) {
            $relative = 'core/'.$category['coreFile'];
            $path = $this->library->absolutePath($relative);

            if (! $this->files->isFile($path) || $this->files->size($path) < 1) {
                $missing[] = $category['slug'];
            }
        }

        $total = count($categories);

        if ($missing !== []) {
            return $this->item(
                'core kit',
                false,
                sprintf(
                    'Faltan %d de %d categorías: %s.',
                    count($missing),
                    $total,
                    implode(', ', array_slice($missing, 0, 8)).(count($missing) > 8 ? '…' : ''),
                ),
                'php artisan audio:core-kit',
            );
        }

        return $this->item('core kit', true, $total.' categorías en disco.');
    }

    /**
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function ffmpegFilters(): array
    {
        $binary = (string) $this->config->get('stories.ffmpeg.binary');
        $path = $this->locate($binary);

        if ($path === null) {
            return $this->item(
                'filtros de ffmpeg',
                false,
                'ffmpeg no está en el PATH: no se pueden listar zoompan, xfade ni loudnorm.',
                'Instala ffmpeg y déjalo en el PATH.',
            );
        }

        $process = new Process([$path, '-hide_banner', '-filters']);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            return $this->item(
                'filtros de ffmpeg',
                false,
                'ffmpeg -filters falló: '.trim($process->getErrorOutput().' '.$process->getOutput()),
                'Instala ffmpeg con libavfilter (zoompan, xfade, loudnorm).',
            );
        }

        $listed = $this->listedFilters($process->getOutput()."\n".$process->getErrorOutput());
        $missing = array_values(array_filter(
            self::RENDER_FILTERS,
            static fn (string $filter): bool => ! in_array($filter, $listed, true),
        ));

        if ($missing !== []) {
            return $this->item(
                'filtros de ffmpeg',
                false,
                'Faltan: '.implode(', ', $missing).'.',
                'Instala ffmpeg con libavfilter (zoompan, xfade, loudnorm).',
            );
        }

        return $this->item('filtros de ffmpeg', true, implode(', ', self::RENDER_FILTERS));
    }

    /**
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function freeDisk(): array
    {
        $workPath = (string) $this->config->get('stories.pipeline.work_path', storage_path('app'));
        $needed = (int) $this->config->get('stories.pipeline.min_free_disk_bytes');
        $free = disk_free_space($workPath);

        if ($free === false) {
            return $this->item(
                'espacio en disco',
                false,
                'No se pudo medir el espacio libre en '.$workPath.'.',
                'Libera espacio en '.$workPath.' (hacen falta '.$this->formatBytes($needed).').',
            );
        }

        if ($free <= $needed) {
            return $this->item(
                'espacio en disco',
                false,
                sprintf(
                    '%s libres en %s; hacen falta más de %s.',
                    $this->formatBytes((int) $free),
                    $workPath,
                    $this->formatBytes($needed),
                ),
                'Libera espacio en '.$workPath.' hasta superar '.$this->formatBytes($needed).'.',
            );
        }

        return $this->item(
            'espacio en disco',
            true,
            sprintf('%s libres en %s.', $this->formatBytes((int) $free), $workPath),
        );
    }

    /**
     * @return list<string>
     */
    private function listedFilters(string $output): array
    {
        $found = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*[A-Za-z.]*\s+(\S+)\s+\S+->/', $line, $match) !== 1) {
                continue;
            }

            $found[] = $match[1];
        }

        return $found;
    }

    private function locate(string $binary): ?string
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $binary) === 1) {
            return $this->files->isFile($binary) && is_executable($binary) ? $binary : null;
        }

        return $this->finder->find($binary);
    }

    /**
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function item(string $name, bool $ok, string $detail, string $fix = ''): array
    {
        return [
            'name' => $name,
            'ok' => $ok,
            'detail' => $detail,
            'fix' => $ok ? '' : $fix,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $gigabytes = $bytes / (1024 * 1024 * 1024);

        return number_format($gigabytes, 1, ',', '.').' GB';
    }
}
