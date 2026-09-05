<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Story;
use App\Models\Thumbnail;
use App\Services\Image\ThumbnailCandidates;
use App\Services\Image\YouTubeThumbnail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Response as InertiaResponse;
use Inertia\ResponseFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ThumbnailController extends Controller
{
    /**
     * Valores con los que nace una composición nueva. Son los mismos que la tabla ya traía
     * por defecto: el texto abajo a la izquierda, sobre viñeta, con la saturación bajada.
     */
    private const DEFAULTS = [
        'font_size' => 132,
        'pos_y' => 58,
        'align' => 'left',
        'vignette' => 55,
        'contrast' => 118,
        'saturation' => 72,
    ];

    public function __construct(
        private readonly ResponseFactory $inertia,
        private readonly ThumbnailCandidates $candidates,
        private readonly YouTubeThumbnail $youtube,
    ) {}

    public function entry(): RedirectResponse|InertiaResponse
    {
        $story = Story::query()
            ->orderByDesc('updated_at')
            ->get()
            ->first(fn (Story $story): bool => $this->candidates->propose($story->slug) !== []);

        if ($story instanceof Story) {
            return redirect()->route('thumbnail.show', $story);
        }

        return $this->inertia->render('Thumbnail', [
            'story' => null,
            'candidates' => [],
            'variants' => [],
            'defaults' => self::DEFAULTS,
        ]);
    }

    public function show(Story $story): InertiaResponse
    {
        return $this->inertia->render('Thumbnail', [
            'story' => [
                'id' => $story->id,
                'slug' => $story->slug,
                'title' => trim((string) $story->title) !== '' ? $story->title : $story->slug,
                'download_name' => $this->youtube->downloadName($story),
            ],
            'candidates' => $this->candidates->propose($story->slug),
            'variants' => $this->variants($story),
            'defaults' => self::DEFAULTS,
        ]);
    }

    public function store(Request $request, Story $story): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'shot_order' => ['required', 'integer', 'min:1'],
            'frame_second' => ['nullable', 'numeric', 'min:0'],
            'line1' => ['nullable', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'line3' => ['nullable', 'string', 'max:255'],
            'font_size' => ['required', 'integer', 'min:40', 'max:260'],
            'pos_y' => ['required', 'integer', 'min:0', 'max:100'],
            'align' => ['required', 'string', Rule::in(['left', 'center', 'right'])],
            'vignette' => ['required', 'integer', 'min:0', 'max:100'],
            'contrast' => ['required', 'integer', 'min:50', 'max:200'],
            'saturation' => ['required', 'integer', 'min:0', 'max:200'],
            // El JPEG ya compuesto por el navegador. Se comprueba entero porque lo que llega
            // por aquí acaba subido a YouTube: si no cumple el formato, mejor saberlo ahora
            // que delante del formulario de subida.
            'image' => [
                'required',
                'file',
                'mimetypes:'.YouTubeThumbnail::MIME,
                'max:'.(int) (YouTubeThumbnail::MAX_BYTES / 1024),
                'dimensions:width='.YouTubeThumbnail::WIDTH.',height='.YouTubeThumbnail::HEIGHT,
            ],
        ]);

        $image = $request->file('image');
        unset($validated['image']);

        // Guardar una variante no la elige: la elección es un gesto aparte, para poder
        // comparar varias al tamaño real antes de decidir.
        $thumbnail = $story->thumbnails()->create([...$validated, 'is_selected' => false]);
        $thumbnail->update(['path' => $this->youtube->store($story, $thumbnail, $image)]);

        return redirect()->route('thumbnail.show', $story);
    }

    /**
     * Descarga la portada tal y como se aprobó, con el nombre y el formato que espera YouTube.
     */
    public function download(Story $story, Thumbnail $thumbnail): Response|BinaryFileResponse
    {
        abort_unless($thumbnail->story_id === $story->id, 404);

        $path = $this->youtube->path($story, $thumbnail);

        if ($path === null) {
            return new Response('', 404, ['Cache-Control' => 'no-store']);
        }

        return (new BinaryFileResponse($path, 200, ['Content-Type' => YouTubeThumbnail::MIME]))
            ->setContentDisposition('attachment', $this->youtube->downloadName($story));
    }

    public function select(Story $story, Thumbnail $thumbnail): RedirectResponse
    {
        abort_unless($thumbnail->story_id === $story->id, 404);

        $story->thumbnails()->update(['is_selected' => false]);
        $thumbnail->update(['is_selected' => true]);

        return redirect()->route('thumbnail.show', $story);
    }

    public function destroy(Story $story, Thumbnail $thumbnail): RedirectResponse
    {
        abort_unless($thumbnail->story_id === $story->id, 404);

        $this->youtube->delete($story, $thumbnail);
        $thumbnail->delete();

        return redirect()->route('thumbnail.show', $story);
    }

    /**
     * La imagen conservada de un plano candidato.
     *
     * Sale de la copia del directorio de la historia, no de la caché: la caché desaparece con
     * story:prune y la portada tiene que seguir ahí cuando el vídeo ya está listo.
     */
    public function image(Request $request, Story $story, int $order): Response|BinaryFileResponse
    {
        $path = $this->candidates->preservedPath($story->slug, $order);

        if ($path === null) {
            return new Response('', 404, ['Cache-Control' => 'no-store']);
        }

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'image/jpeg']);
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->setAutoEtag();
        $response->setAutoLastModified();
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function variants(Story $story): array
    {
        return $story->thumbnails()
            ->orderByDesc('is_selected')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Thumbnail $thumbnail): array => [
                'id' => $thumbnail->id,
                'name' => $thumbnail->name,
                'shot_order' => $thumbnail->shot_order,
                'frame_second' => $thumbnail->frame_second,
                'line1' => $thumbnail->line1,
                'line2' => $thumbnail->line2,
                'line3' => $thumbnail->line3,
                'font_size' => $thumbnail->font_size,
                'pos_y' => $thumbnail->pos_y,
                'align' => $thumbnail->align,
                'vignette' => $thumbnail->vignette,
                'contrast' => $thumbnail->contrast,
                'saturation' => $thumbnail->saturation,
                'is_selected' => $thumbnail->is_selected,
                'has_file' => $this->youtube->path($story, $thumbnail) !== null,
            ])
            ->all();
    }
}
