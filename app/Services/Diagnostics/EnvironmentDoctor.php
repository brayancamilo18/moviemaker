<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use App\Contracts\TextToSpeech;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\TranscriptTimer;
use App\Services\Tts\KokoroTts;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

final class EnvironmentDoctor
{
    private readonly string $ffmpeg;

    private readonly string $ffprobe;

    private readonly string $whisperBinary;

    private readonly string $whisperModel;

    private readonly string $geminiKey;

    private readonly string $anthropicKey;

    private readonly string $freesoundToken;

    private readonly int $imageWidth;

    private readonly int $videoWidth;

    private readonly float $zoomMax;

    public function __construct(
        private Filesystem $files,
        private TextToSpeech $tts,
        private AudioLibrary $library,
        private TranscriptTimer $timer,
        private ExecutableFinder $finder,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe');
        $this->whisperBinary = (string) $config->get('stories.whisper.binary');
        $this->whisperModel = (string) $config->get('stories.whisper.model');
        $this->geminiKey = trim((string) $config->get('stories.llm.gemini.api_key'));
        $this->anthropicKey = trim((string) $config->get('stories.llm.anthropic.api_key'));
        $this->freesoundToken = trim((string) $config->get('stories.audio.freesound.token'));
        $this->imageWidth = (int) $config->get('stories.images.width');
        $this->videoWidth = (int) $config->get('stories.video.width');
        $this->zoomMax = (float) $config->get('stories.video.zoom_max');
    }

    /**
     * @return list<array{name: string, ok: bool, blocking: bool, detail: string}>
     */
    public function checks(): array
    {
        return [
            $this->binary('ffmpeg', $this->ffmpeg, 'Sin él no hay mezcla ni render.'),
            $this->binary('ffprobe', $this->ffprobe, 'Sin él NarrationClock no puede medir el máster.'),
            $this->binary('whisper', $this->whisperBinary, 'Sin él no hay timings.json.'),
            $this->whisperModel(),
            $this->sidecar(),
            $this->llmProvider(),
            $this->secret(
                'GEMINI_API_KEY',
                $this->geminiKey,
                false,
                'Sin ella el guion sale por el proveedor de respaldo.',
            ),
            $this->secret(
                'ANTHROPIC_API_KEY',
                $this->anthropicKey,
                false,
                'Sin ella no hay respaldo cuando Gemini está saturado.',
            ),
            $this->secret(
                'FREESOUND_TOKEN',
                $this->freesoundToken,
                false,
                'Sin él story:sounds solo dispone del core kit y de los sintéticos.',
            ),
            $this->manifest(),
            $this->sourceResolution(),
        ];
    }

    /**
     * @param  list<array{name: string, ok: bool, blocking: bool, detail: string}>  $checks
     */
    public function hasBlockingFailure(array $checks): bool
    {
        foreach ($checks as $check) {
            if (! $check['ok'] && $check['blocking']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, detail: string}
     */
    private function binary(string $name, string $binary, string $consequence): array
    {
        if ($binary === '') {
            return $this->check($name, false, true, 'No hay binario configurado. '.$consequence);
        }

        $path = $this->locate($binary);

        if ($path === null) {
            return $this->check($name, false, true, sprintf(
                "No se encuentra '%s' ni en el PATH ni como ruta ejecutable. %s",
                $binary,
                $consequence,
            ));
        }

        return $this->check($name, true, true, $path);
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, detail: string}
     */
    private function whisperModel(): array
    {
        $problem = $this->timer->modelProblem();

        if ($problem !== null) {
            return $this->check('modelo de whisper', false, true, $problem);
        }

        return $this->check('modelo de whisper', true, true, sprintf(
            '%s (%s)',
            $this->whisperModel,
            $this->humanSize($this->files->size($this->whisperModel)),
        ));
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, detail: string}
     */
    private function sidecar(): array
    {
        try {
            $available = $this->tts->isAvailable();
        } catch (Throwable $exception) {
            return $this->check('sidecar de Kokoro', false, true, 'No responde: '.$exception->getMessage());
        }

        if (! $available) {
            return $this->check(
                'sidecar de Kokoro',
                false,
                true,
                'No responde en /health. Arráncalo con: '.KokoroTts::START_COMMAND,
            );
        }

        return $this->check('sidecar de Kokoro', true, true, 'Responde /health con el modelo cargado.');
    }

    /**
     * Lo bloqueante no es una clave concreta, sino quedarse sin ningún proveedor de LLM: mientras
     * quede uno con credencial, el guion se puede escribir.
     *
     * @return array{name: string, ok: bool, blocking: bool, detail: string}
     */
    private function llmProvider(): array
    {
        $available = [];

        if ($this->geminiKey !== '') {
            $available[] = 'Gemini';
        }

        if ($this->anthropicKey !== '') {
            $available[] = 'Anthropic';
        }

        if ($available === []) {
            return $this->check(
                'proveedor de LLM',
                false,
                true,
                'Ni GEMINI_API_KEY ni ANTHROPIC_API_KEY están definidas: no se puede generar el guion.',
            );
        }

        return $this->check('proveedor de LLM', true, true, implode(' y ', $available));
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, detail: string}
     */
    private function secret(string $name, string $value, bool $blocking, string $consequence): array
    {
        if ($value === '') {
            return $this->check($name, false, $blocking, 'ausente. '.$consequence);
        }

        return $this->check($name, true, $blocking, 'definida');
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, detail: string}
     */
    private function manifest(): array
    {
        $path = $this->library->root().DIRECTORY_SEPARATOR.'manifest.json';

        if (! $this->files->isFile($path)) {
            return $this->check(
                'manifest de audio',
                false,
                false,
                'No existe '.$path.'. Se crea al importar o descargar clips.',
            );
        }

        try {
            $clips = $this->library->allClips();
        } catch (Throwable $exception) {
            return $this->check('manifest de audio', false, true, $exception->getMessage());
        }

        $missing = [];

        foreach ($clips as $clip) {
            $file = (string) ($clip['file'] ?? '');

            if ($file === '' || ! $this->files->isFile($this->library->absolutePath($file))) {
                $missing[] = $file !== '' ? $file : '(clip sin fichero)';
            }
        }

        if ($missing !== []) {
            return $this->check('manifest de audio', false, false, sprintf(
                '%d de %d clips no están en disco: %s',
                count($missing),
                count($clips),
                $this->summarize($missing),
            ));
        }

        return $this->check('manifest de audio', true, false, count($clips).' clips, todos en disco.');
    }

    /**
     * Un plano se ve tan nítido como la imagen que lo alimenta, y con el zoom al máximo hacen falta
     * video.width × zoom_max píxeles de fuente para no estirar nada. Quedarse corto no es un fallo
     * que se arregle en esta máquina: es el techo del proveedor de imágenes. Aquí solo se pone el
     * número a la vista, porque hasta ahora era invisible y se daba por hecho que la fuente cubría.
     *
     * @return array{name: string, ok: bool, blocking: bool, detail: string}
     */
    private function sourceResolution(): array
    {
        $name = 'resolución de las fuentes';
        $needed = (int) ceil($this->videoWidth * $this->zoomMax);

        if ($this->imageWidth < $needed) {
            return $this->check($name, true, false, sprintf(
                'Fuentes de %d px para una salida de %d px: con zoom_max %.2f harían falta %d, '
                .'así que los planos se estiran hasta %.2fx. Es el techo del proveedor.',
                $this->imageWidth,
                $this->videoWidth,
                $this->zoomMax,
                $needed,
                $needed / max(1, $this->imageWidth),
            ));
        }

        return $this->check($name, true, false, sprintf(
            'Fuentes de %d px para una salida de %d px con zoom_max %.2f: hacen falta %d y sobran.',
            $this->imageWidth,
            $this->videoWidth,
            $this->zoomMax,
            $needed,
        ));
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, detail: string}
     */
    private function check(string $name, bool $ok, bool $blocking, string $detail): array
    {
        return [
            'name' => $name,
            'ok' => $ok,
            'blocking' => $blocking,
            'detail' => $detail,
        ];
    }

    private function locate(string $binary): ?string
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return $this->files->isFile($binary) && is_executable($binary) ? $binary : null;
        }

        return $this->finder->find($binary);
    }

    /**
     * @param  list<string>  $files
     */
    private function summarize(array $files, int $limit = 5): string
    {
        $shown = array_slice($files, 0, $limit);
        $rest = count($files) - count($shown);
        $text = implode(', ', $shown);

        return $rest > 0 ? $text.' y '.$rest.' más' : $text;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KiB', $bytes / 1024);
        }

        return sprintf('%.1f MiB', $bytes / 1024 / 1024);
    }
}
