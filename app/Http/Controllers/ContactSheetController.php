<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Story;
use App\Services\Image\ContactSheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Response as InertiaResponse;
use Inertia\ResponseFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ContactSheetController extends Controller
{
    public function __construct(
        private readonly ResponseFactory $inertia,
        private readonly ContactSheet $sheet,
    ) {}

    /**
     * Entrada sin historia: manda a la última que tenga planos que enseñar.
     */
    public function entry(): RedirectResponse|InertiaResponse
    {
        $story = Story::query()
            ->orderByDesc('updated_at')
            ->get()
            ->first(fn (Story $story): bool => $this->sheet->shots($story->slug) !== null);

        if ($story instanceof Story) {
            return redirect()->route('sheet.show', $story);
        }

        return $this->inertia->render('ContactSheet', [
            'story' => null,
            'shots' => [],
            'stats' => null,
        ]);
    }

    public function show(Story $story): InertiaResponse
    {
        $shots = $this->sheet->shots($story->slug);

        return $this->inertia->render('ContactSheet', [
            'story' => [
                'id' => $story->id,
                'slug' => $story->slug,
                'title' => trim((string) $story->title) !== '' ? $story->title : $story->slug,
                'status_label' => $story->status->label(),
                'status_color' => $story->status->color(),
            ],
            'shots' => $shots ?? [],
            'stats' => $this->sheet->stats($story->slug),
        ]);
    }

    /**
     * La imagen de un plano. Se direcciona por número de plano y no por ruta: lo que hay
     * escrito en shots.json es una ruta absoluta que viene de un fichero, y servirla tal cual
     * convertiría esto en un lector de ficheros arbitrarios.
     */
    public function image(Request $request, Story $story, int $order): Response|BinaryFileResponse
    {
        $path = $this->sheet->imagePath($story->slug, $order);

        if ($path === null) {
            return new Response('', 404, ['Cache-Control' => 'no-store']);
        }

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'image/jpeg']);

        // El nombre del fichero en caché es el hash de su prompt, así que su contenido no
        // cambia nunca: una imagen que ya está en el navegador no se vuelve a pedir. Privada
        // porque son fotogramas de material sin publicar.
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->setAutoEtag();
        $response->setAutoLastModified();

        // Convierte la respuesta en un 304 sin cuerpo cuando el navegador ya la tiene.
        $response->isNotModified($request);

        return $response;
    }
}
