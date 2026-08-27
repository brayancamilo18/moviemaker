<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Única fuente del texto de atribución: lo usan audio:credits (ATTRIBUTION.md del repositorio)
 * y story:mix (credits.txt de cada historia).
 */
final class AttributionWriter
{
    public function __construct(
        private AudioLibrary $library,
        private Filesystem $files,
    ) {}

    /**
     * Créditos de todo clip indexado que exige atribución, entre o no en un vídeo.
     *
     * @return list<array{file: string, author: string, sourceUrl: string, license: string}>
     */
    public function libraryCredits(): array
    {
        $credits = [];

        foreach ($this->library->attributionClips() as $clip) {
            $credits[] = $this->credit(
                (string) ($clip['file'] ?? ''),
                (string) ($clip['author'] ?? ''),
                (string) ($clip['source_url'] ?? ''),
                (string) ($clip['license'] ?? ''),
            );
        }

        return $this->deduplicate($credits);
    }

    /**
     * Créditos de los clips que han entrado de verdad en una mezcla.
     *
     * @param  list<array<string, mixed>>  $cues
     * @return list<array{file: string, author: string, sourceUrl: string, license: string}>
     */
    public function cueCredits(array $cues): array
    {
        $credits = [];

        foreach ($cues as $cue) {
            if (($cue['attributionRequired'] ?? false) !== true) {
                continue;
            }

            $credits[] = $this->credit(
                (string) ($cue['file'] ?? ''),
                (string) ($cue['author'] ?? ''),
                (string) ($cue['sourceUrl'] ?? ''),
                (string) ($cue['license'] ?? ''),
            );
        }

        return $this->deduplicate($credits);
    }

    /**
     * @param  list<array{file: string, author: string, sourceUrl: string, license: string}>  $credits
     * @return list<string>
     */
    public function lines(array $credits): array
    {
        $lines = [];

        foreach ($credits as $credit) {
            $lines[] = sprintf(
                '"%s" by %s — %s — %s',
                $credit['file'],
                $credit['author'],
                $credit['sourceUrl'],
                $credit['license'],
            );
        }

        return $lines;
    }

    /**
     * ATTRIBUTION.md: el fichero versionado con el que el repositorio cumple la obligación
     * de crédito de los clips CC BY.
     *
     * @param  list<array{file: string, author: string, sourceUrl: string, license: string}>  $credits
     */
    public function document(array $credits): string
    {
        $document = <<<'MARKDOWN'
        # Atribución de sonidos

        Fichero generado por `php artisan audio:credits --write`. No lo edites a mano.

        El código de este repositorio y los clips de audio tienen licencias distintas. El código es
        propietario (`"license": "proprietary"` en `composer.json`) y no cubre el audio: cada clip
        conserva la licencia con la que su autor lo publicó. Distribuir el código no otorga ningún
        derecho sobre los sonidos, ni al contrario.

        Aquí solo aparecen los clips marcados con `attribution_required`. Exigen crédito visible en
        cualquier vídeo que los use. Los CC0 no lo exigen y por eso no se listan; si un clip aparece
        con licencia `unknown` es que su WAV está en disco pero no en el índice: ante la duda se
        acredita.

        MARKDOWN;

        foreach ($this->groupByLicense($credits) as $license => $group) {
            $document .= "\n## ".$license."\n\n";

            foreach ($this->lines($group) as $line) {
                $document .= '- '.$line."\n";
            }
        }

        return $document;
    }

    /**
     * credits.txt: la atribución que acompaña al máster de una historia concreta.
     *
     * @param  list<array{file: string, author: string, sourceUrl: string, license: string}>  $credits
     */
    public function storyDocument(string $slug, array $credits): string
    {
        $document = 'Créditos de sonido — '.$slug."\n";
        $document .= "Generado por php artisan story:mix. No lo edites a mano.\n\n";

        if ($credits === []) {
            return $document."Ningún clip de esta mezcla exige atribución.\n";
        }

        $document .= "Estos clips exigen atribución: el crédito debe aparecer en la descripción del vídeo.\n\n";

        foreach ($this->lines($credits) as $line) {
            $document .= $line."\n";
        }

        return $document;
    }

    public function write(string $path, string $contents): void
    {
        $this->files->ensureDirectoryExists(dirname($path));

        if ($this->files->put($path, $contents) === false) {
            throw new RuntimeException('No se pudo escribir la atribución en '.$path.'.');
        }
    }

    /**
     * @return array{file: string, author: string, sourceUrl: string, license: string}
     */
    private function credit(string $file, string $author, string $sourceUrl, string $license): array
    {
        $author = trim($author);
        $license = trim($license);

        return [
            'file' => $this->relative(trim($file)),
            'author' => $author !== '' ? $author : AudioLibrary::AUTHOR_UNKNOWN,
            'sourceUrl' => trim($sourceUrl),
            'license' => $license !== '' ? $license : AudioLibrary::LICENSE_UNKNOWN,
        ];
    }

    /**
     * Los clips resueltos en tiempo de mezcla llegan con ruta absoluta; los de sounds.json, con
     * ruta relativa a la librería. El crédito se escribe siempre igual.
     */
    private function relative(string $file): string
    {
        $root = rtrim($this->library->root(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($file, $root)) {
            return str_replace('\\', '/', substr($file, strlen($root)));
        }

        return $file;
    }

    /**
     * @param  list<array{file: string, author: string, sourceUrl: string, license: string}>  $credits
     * @return list<array{file: string, author: string, sourceUrl: string, license: string}>
     */
    private function deduplicate(array $credits): array
    {
        $unique = [];
        $seen = [];

        foreach ($credits as $credit) {
            $key = $credit['file'].'|'.$credit['sourceUrl'];

            if ($credit['file'] === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $credit;
        }

        return $unique;
    }

    /**
     * @param  list<array{file: string, author: string, sourceUrl: string, license: string}>  $credits
     * @return array<string, list<array{file: string, author: string, sourceUrl: string, license: string}>>
     */
    private function groupByLicense(array $credits): array
    {
        $grouped = [];

        foreach ($credits as $credit) {
            $grouped[$credit['license']][] = $credit;
        }

        return $grouped;
    }
}
