<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use App\Contracts\TextToSpeech;
use App\Models\Story;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\TranscriptTimer;
use App\Services\Pipeline\QueueHealth;
use App\Services\Tts\KokoroTts;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
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

    private readonly int $internetTimeout;

    private readonly string $workerCommand;

    private readonly string $geminiProbe;

    private readonly string $anthropicProbe;

    public function __construct(
        private Filesystem $files,
        private TextToSpeech $tts,
        private AudioLibrary $library,
        private TranscriptTimer $timer,
        private ExecutableFinder $finder,
        private readonly Repository $config,
        private readonly DatabaseManager $db,
        private readonly QueueHealth $queue,
        private readonly Factory $http,
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
        $this->internetTimeout = (int) $config->get('stories.doctor.internet_timeout');
        $this->workerCommand = (string) $config->get('stories.doctor.worker_command');
        $this->geminiProbe = (string) $config->get('stories.doctor.gemini_probe');
        $this->anthropicProbe = (string) $config->get('stories.doctor.anthropic_probe');
    }

    /**
     * @return list<array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}>
     */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->queue(),
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
                showTail: true,
            ),
            $this->secret(
                'ANTHROPIC_API_KEY',
                $this->anthropicKey,
                false,
                'Sin ella no hay respaldo cuando Gemini está saturado.',
                showTail: true,
            ),
            $this->secret(
                'FREESOUND_TOKEN',
                $this->freesoundToken,
                false,
                'Sin él story:sounds solo dispone del core kit y de los sintéticos.',
            ),
            $this->internet('salida a Gemini', $this->geminiProbe),
            $this->internet('salida a Anthropic', $this->anthropicProbe),
            $this->configCache(),
            $this->manifest(),
            $this->sourceResolution(),
        ];
    }

    /**
     * @param  list<array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}>  $checks
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
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
     */
    private function database(): array
    {
        $table = (new Story)->getTable();

        try {
            $schema = $this->db->connection()->getSchemaBuilder();

            if (! $schema->hasTable($table)) {
                return $this->check(
                    'base de datos',
                    false,
                    true,
                    "La conexión responde, pero la tabla {$table} no existe.",
                    'php artisan migrate',
                );
            }

            $count = (int) $this->db->connection()->table($table)->count();
        } catch (Throwable $exception) {
            return $this->check(
                'base de datos',
                false,
                true,
                'No se puede consultar stories: '.$exception->getMessage(),
                'php artisan migrate',
            );
        }

        return $this->check(
            'base de datos',
            true,
            true,
            sprintf('Tabla %s consultable (%d filas).', $table, $count),
        );
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
     */
    private function queue(): array
    {
        $connection = (string) $this->config->get('queue.default');
        $status = $this->queue->status();

        if ($connection !== 'database') {
            return $this->check(
                'cola',
                true,
                false,
                sprintf(
                    'QUEUE_CONNECTION=%s: la comprobación de worker no aplica (%d pendientes, %d fallidos).',
                    $connection,
                    $status['pending'],
                    $status['failed'],
                ),
            );
        }

        $detail = sprintf(
            'QUEUE_CONNECTION=%s: %d en espera, %d en curso, %d fallidos.',
            $connection,
            $status['waiting'],
            $status['running'],
            $status['failed'],
        );

        if ($status['likelyNoWorker']) {
            return $this->check(
                'cola',
                false,
                false,
                $this->workerCommand.' — '.$detail.' Worker parado.',
                $this->workerCommand,
            );
        }

        if ($status['workerBusy']) {
            return $this->check(
                'cola',
                false,
                false,
                $detail.' Worker ocupado; hay cola detrás.',
            );
        }

        return $this->check('cola', true, false, $detail);
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
     */
    private function internet(string $name, string $url): array
    {
        try {
            $response = $this->http->timeout($this->internetTimeout)->get($url);
        } catch (ConnectionException $exception) {
            $message = $exception->getMessage();
            $hint = $this->connectionHint($message);

            return $this->check(
                $name,
                false,
                true,
                ($hint !== null ? $hint.' ' : '').$message,
                'php artisan config:clear && php artisan cache:clear',
            );
        }

        return $this->check(
            $name,
            true,
            true,
            'HTTP '.$response->status(),
        );
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
     */
    private function configCache(): array
    {
        $path = $this->absolutePath((string) $this->config->get('stories.doctor.config_cache_path'));

        if (! $this->files->isFile($path)) {
            return $this->check(
                'config cacheada',
                true,
                false,
                'No hay configuración cacheada.',
            );
        }

        return $this->check(
            'config cacheada',
            false,
            false,
            'Hay un config.php cacheado. Un cache con claves viejas hace que el .env parezca no aplicarse.',
            'php artisan config:clear && php artisan cache:clear',
        );
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
     */
    private function binary(string $name, string $binary, string $consequence): array
    {
        if ($binary === '') {
            return $this->check(
                $name,
                false,
                true,
                'No hay binario configurado. '.$consequence,
                'Instala '.$name.' y déjalo en el PATH.',
            );
        }

        $path = $this->locate($binary);

        if ($path === null) {
            return $this->check($name, false, true, sprintf(
                "No se encuentra '%s' ni en el PATH ni como ruta ejecutable. %s",
                $binary,
                $consequence,
            ), 'Instala '.$name.' y déjalo en el PATH.');
        }

        return $this->check($name, true, true, $path);
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
     */
    private function whisperModel(): array
    {
        $problem = $this->timer->modelProblem();

        if ($problem !== null) {
            return $this->check(
                'modelo de whisper',
                false,
                true,
                $problem,
                'Coloca un ggml-*.bin en storage/app/whisper/ o define WHISPER_MODEL.',
            );
        }

        return $this->check('modelo de whisper', true, true, sprintf(
            '%s (%s)',
            $this->whisperModel,
            $this->humanSize($this->files->size($this->whisperModel)),
        ));
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
     */
    private function sidecar(): array
    {
        try {
            $available = $this->tts->isAvailable();
        } catch (Throwable $exception) {
            return $this->check(
                'sidecar de Kokoro',
                false,
                true,
                'No responde: '.$exception->getMessage(),
                KokoroTts::START_COMMAND,
            );
        }

        if (! $available) {
            return $this->check(
                'sidecar de Kokoro',
                false,
                true,
                'No responde en /health. Arráncalo con: '.KokoroTts::START_COMMAND,
                KokoroTts::START_COMMAND,
            );
        }

        return $this->check('sidecar de Kokoro', true, true, 'Responde /health con el modelo cargado.');
    }

    /**
     * Lo bloqueante no es una clave concreta, sino quedarse sin ningún proveedor de LLM: mientras
     * quede uno con credencial, el guion se puede escribir.
     *
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
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
                'Añade GEMINI_API_KEY o ANTHROPIC_API_KEY al .env y ejecuta php artisan config:clear.',
            );
        }

        return $this->check('proveedor de LLM', true, true, implode(' y ', $available));
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
     */
    private function secret(
        string $name,
        string $value,
        bool $blocking,
        string $consequence,
        bool $showTail = false,
    ): array {
        if ($value === '') {
            return $this->check(
                $name,
                false,
                $blocking,
                'ausente. '.$consequence,
                'Añade '.$name.' al .env y ejecuta php artisan config:clear.',
            );
        }

        $detail = 'definida';

        if ($showTail && strlen($value) >= 4) {
            $detail = 'termina en '.substr($value, -4);
        }

        return $this->check($name, true, $blocking, $detail);
    }

    /**
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
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
                'php artisan audio:core-kit',
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
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
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
     * @return array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}
     */
    private function check(
        string $name,
        bool $ok,
        bool $blocking,
        string $detail,
        string $fix = '',
    ): array {
        return [
            'name' => $name,
            'ok' => $ok,
            'blocking' => $blocking,
            'status' => $ok ? 'green' : ($blocking ? 'red' : 'amber'),
            'detail' => $detail,
            'fix' => $fix,
        ];
    }

    private function connectionHint(string $message): ?string
    {
        $haystack = strtolower($message);

        if (str_contains($haystack, 'could not resolve host')) {
            return 'Sin DNS. Comprueba la conexión de red de la máquina.';
        }

        if (str_contains($haystack, 'ssl certificate') || str_contains($haystack, 'certificate verify')) {
            return 'Problema de certificados TLS en PHP. Revisa curl.cainfo en php.ini.';
        }

        if (str_contains($haystack, 'timed out') || str_contains($haystack, 'timeout')) {
            return 'La petición agotó el tiempo. Puede ser un cortafuegos o un proxy.';
        }

        if (str_contains($haystack, 'connection refused')) {
            return 'Conexión rechazada. Suele ser un proxy local o una VPN.';
        }

        return null;
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') {
            return base_path('bootstrap/cache/config.php');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
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
