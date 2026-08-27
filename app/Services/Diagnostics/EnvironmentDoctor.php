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

    private readonly string $freesoundToken;

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
        $this->geminiKey = trim((string) $config->get('stories.gemini.api_key'));
        $this->freesoundToken = trim((string) $config->get('stories.audio.freesound.token'));
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
            $this->secret('GEMINI_API_KEY', $this->geminiKey, true, 'Sin ella no se puede generar el guion.'),
            $this->secret(
                'FREESOUND_TOKEN',
                $this->freesoundToken,
                false,
                'Sin él story:sounds solo dispone del core kit y de los sintéticos.',
            ),
            $this->manifest(),
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
