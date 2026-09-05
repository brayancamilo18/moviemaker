<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\TextToSpeech;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;

/**
 * Suelta lo que una historia ya no necesita cuando su MP4 existe.
 *
 * Las dos cachés que crecen se comportan distinto y por eso se tratan distinto: las
 * imágenes no se reutilizan jamás entre historias, porque la clave lleva dentro la
 * descripción del plano y la biblia visual, así que en cuanto hay vídeo no valen nada.
 * La voz sí tiene una parte reutilizable —la careta y el cierre son texto fijo de canal—
 * y esa se respeta, porque volver a sintetizarla se paga.
 */
final class PruneStoryCommand extends Command
{
    protected $signature = 'story:prune
        {file? : Ruta al JSON del guion. Sin ella, todas las historias con MP4}
        {--dry-run : Enseña lo que soltaría sin borrar nada}
        {--keep-images : Conserva las imágenes y suelta solo la voz}';

    protected $description = 'Libera imágenes y voz cacheadas de historias que ya tienen su vídeo';

    public function handle(TextToSpeech $tts, Filesystem $files, Repository $config): int
    {
        $directorio = storage_path('app/'.$config->get('stories.output_path'));
        $file = $this->argument('file');

        $slugs = is_string($file) && $file !== ''
            ? [basename($file, '.json')]
            : $this->allSlugs($directorio, $files);

        if ($slugs === []) {
            $this->components->warn('No hay ninguna historia que depurar.');

            return self::SUCCESS;
        }

        $protegidas = $this->channelSentences($config);
        $filas = [];
        $total = 0;

        foreach ($slugs as $slug) {
            $resultado = $this->pruneSlug($slug, $directorio, $tts, $files, $protegidas);

            if ($resultado === null) {
                continue;
            }

            $filas[] = $resultado['fila'];
            $total += $resultado['bytes'];
        }

        if ($filas === []) {
            $this->components->warn('Ninguna historia cumple las condiciones: hace falta que el MP4 exista.');

            return self::SUCCESS;
        }

        $this->table(['Historia', 'Imágenes', 'Frases', 'Protegidas', 'Libera'], $filas);

        $this->components->info(sprintf(
            '%s %s en total.',
            $this->option('dry-run') ? 'Se liberarían' : 'Liberados',
            $this->humanize($total),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{fila: array<int, string>, bytes: int}|null
     */
    private function pruneSlug(
        string $slug,
        string $directorio,
        TextToSpeech $tts,
        Filesystem $files,
        array $protegidas,
    ): ?array {
        $carpeta = $directorio.DIRECTORY_SEPARATOR.$slug;

        // Sin MP4 la historia sigue viva y su caché es justo lo que la salva de repetirse.
        if (! $files->exists($carpeta.DIRECTORY_SEPARATOR.'video.mp4')) {
            $this->components->twoColumnDetail($slug, '<fg=yellow>sin MP4, se deja intacta</>');

            return null;
        }

        $seco = (bool) $this->option('dry-run');
        $bytes = 0;

        $imagenes = $this->option('keep-images')
            ? ['n' => 0, 'bytes' => 0]
            : $this->pruneImages($carpeta, $files, $seco);
        $bytes += $imagenes['bytes'];

        $voz = $this->pruneVoice($carpeta, $tts, $files, $protegidas, $seco);
        $bytes += $voz['bytes'];

        return [
            'bytes' => $bytes,
            'fila' => [
                $slug,
                (string) $imagenes['n'],
                (string) $voz['n'],
                (string) $voz['protegidas'],
                $this->humanize($bytes),
            ],
        ];
    }

    /**
     * @return array{n: int, bytes: int}
     */
    private function pruneImages(string $carpeta, Filesystem $files, bool $seco): array
    {
        $plan = $this->readJson($carpeta.DIRECTORY_SEPARATOR.'shots.json', $files);
        $n = 0;
        $bytes = 0;

        foreach (($plan['shots'] ?? []) as $shot) {
            $path = $shot['imagePath'] ?? null;

            if (! is_string($path) || ! $files->exists($path)) {
                continue;
            }

            $bytes += (int) $files->size($path);
            $n++;

            if (! $seco) {
                $files->delete($path);
            }
        }

        return ['n' => $n, 'bytes' => $bytes];
    }

    /**
     * @param  list<string>  $protegidas
     * @return array{n: int, bytes: int, protegidas: int}
     */
    private function pruneVoice(
        string $carpeta,
        TextToSpeech $tts,
        Filesystem $files,
        array $protegidas,
        bool $seco,
    ): array {
        $timings = $this->readJson($carpeta.DIRECTORY_SEPARATOR.'timings.json', $files);
        $n = 0;
        $bytes = 0;
        $saltadas = 0;

        foreach (($timings['sentences'] ?? []) as $frase) {
            $texto = trim((string) ($frase['ttsText'] ?? $frase['text'] ?? ''));

            if ($texto === '') {
                continue;
            }

            if (in_array($texto, $protegidas, true)) {
                $saltadas++;

                continue;
            }

            $ocupa = $tts->cachedBytes($texto);

            if ($ocupa === 0) {
                continue;
            }

            $bytes += $ocupa;
            $n++;

            if (! $seco) {
                $tts->forget($texto);
            }
        }

        return ['n' => $n, 'bytes' => $bytes, 'protegidas' => $saltadas];
    }

    /**
     * Careta y cierre: texto fijo del canal, idéntico en cada historia. Es lo único que
     * la caché de voz reutiliza de verdad, así que soltarlo sería pagarlo otra vez.
     *
     * @return list<string>
     */
    private function channelSentences(Repository $config): array
    {
        $frases = [];

        foreach (['stories.story.intro.text', 'stories.story.outro.text'] as $clave) {
            $texto = trim((string) $config->get($clave));

            if ($texto === '') {
                continue;
            }

            foreach (preg_split('/(?<=[.!?])\s+/u', $texto) ?: [] as $frase) {
                $frase = trim($frase);

                if ($frase !== '') {
                    $frases[] = $frase;
                }
            }
        }

        return $frases;
    }

    /**
     * @return list<string>
     */
    private function allSlugs(string $directorio, Filesystem $files): array
    {
        if (! $files->isDirectory($directorio)) {
            return [];
        }

        $slugs = [];

        foreach ($files->directories($directorio) as $carpeta) {
            $slugs[] = basename($carpeta);
        }

        sort($slugs);

        return $slugs;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path, Filesystem $files): array
    {
        if (! $files->exists($path)) {
            return [];
        }

        try {
            $data = json_decode($files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function humanize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.').' MB';
        }

        return number_format($bytes / 1024, 0, ',', '.').' KB';
    }
}
