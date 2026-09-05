<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\Models\Story;
use App\Models\Thumbnail;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Guarda la portada ya compuesta, en lo que YouTube acepta.
 *
 * El fichero lo compone el navegador y no el servidor, a propósito. La composición se define
 * en CSS —filtro de contraste y saturación, degradado radial de viñeta, tres líneas con su
 * interletraje y su sombra— y el único sitio donde eso se puede reproducir exactamente es el
 * mismo motor que lo está pintando. Rehacerlo aquí con GD significaría reimplementar las
 * matrices de filtro de CSS y el dibujado de texto a mano, y bastaría con que la fuente del
 * servidor no fuese la del navegador para que lo descargado no se pareciera a lo aprobado.
 *
 * Lo que sí hace el servidor es no fiarse: comprueba tamaño, formato y peso antes de escribir.
 */
final class YouTubeThumbnail
{
    /** Lo que YouTube espera de una portada. */
    public const WIDTH = 1280;

    public const HEIGHT = 720;

    /** Tope de peso de YouTube para una portada. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MIME = 'image/jpeg';

    private readonly string $storiesDirectory;

    public function __construct(
        private Filesystem $files,
        Config $config,
    ) {
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    /**
     * Escribe la portada en el directorio de la historia y devuelve su ruta relativa.
     */
    public function store(Story $story, Thumbnail $thumbnail, UploadedFile $file): string
    {
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$story->slug;
        $this->files->ensureDirectoryExists($directory);

        $name = 'miniatura-'.$thumbnail->id.'.jpg';
        $file->move($directory, $name);

        return $name;
    }

    /**
     * Ruta absoluta de la portada de una variante, o null si esa variante no llegó a componerse.
     */
    public function path(Story $story, Thumbnail $thumbnail): ?string
    {
        if (! is_string($thumbnail->path) || $thumbnail->path === '') {
            return null;
        }

        // El nombre lo pone store(), pero la fila es editable: nunca se compone una ruta con
        // algo que no sea un nombre de fichero pelado.
        $name = basename($thumbnail->path);
        $path = $this->storiesDirectory.DIRECTORY_SEPARATOR.$story->slug.DIRECTORY_SEPARATOR.$name;

        return $this->files->isFile($path) ? $path : null;
    }

    /**
     * Nombre con el que se descarga. Lleva el título porque el fichero acaba suelto en la
     * carpeta de descargas junto a las portadas de las otras historias.
     */
    public function downloadName(Story $story): string
    {
        $title = trim((string) $story->title);
        $base = Str::slug($title !== '' ? $title : $story->slug);

        return ($base !== '' ? $base : 'miniatura').'-miniatura.jpg';
    }

    public function delete(Story $story, Thumbnail $thumbnail): void
    {
        $path = $this->path($story, $thumbnail);

        if ($path !== null) {
            $this->files->delete($path);
        }
    }
}
