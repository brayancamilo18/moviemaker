<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\DataObjects\Shot;
use App\DataObjects\VisualBible;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final class ShotPromptBuilder
{
    // Prioridad de la parte descriptiva: cuando no cabe todo, se cae primero el rango más alto.
    private const RANK_DESCRIPTION = 1;

    private const RANK_THREAT = 2;

    private const RANK_JOURNEY = 3;

    private const RANK_SETTING = 4;

    private const RANK_REST = 5;

    /**
     * Ocultación con la que sale el ente, que es la única figura que puede aparecer en cuadro. Son
     * afirmaciones, no negaciones: los negativos viajan como texto dentro del prompt porque el
     * proveedor no expone rama negativa, y «no clear facial features» no impide una cara mientras
     * que una silueta a contraluz no tiene ninguna que resolver. Todas valen a cualquier escala,
     * así que ninguna puede contradecir el encuadre del plano.
     *
     * @var list<string>
     */
    private const OCCLUSIONS = [
        'seen from behind',
        'silhouette against a light source',
        'backlit figure',
        'features lost in shadow',
        'face turned away',
        'body turned away from the camera',
    ];

    /**
     * @var array<string, string>
     */
    private const EUPHEMISMS = [
        'blood-soaked' => 'dark stained',
        'bloodstained' => 'dark stained',
        'bloody' => 'stained',
        'blood' => 'dark stain',
        'corpses' => 'still figures',
        'corpse' => 'still figure',
        'dead bodies' => 'still figures',
        'dead body' => 'still figure',
        'skeleton' => 'bone figure',
        'skulls' => 'bones',
        'skull' => 'bone',
        'gore' => 'dark stain',
        'gory' => 'stained',
    ];

    private readonly string $styleSuffix;

    private readonly int $maxWords;

    public function __construct(Repository $config)
    {
        $this->styleSuffix = trim((string) $config->get('stories.image_style_suffix'));
        $this->maxWords = (int) $config->get('stories.images.max_prompt_words');
    }

    public function build(Shot $shot, VisualBible $bible): string
    {
        $description = trim($shot->description);

        if ($description === '') {
            throw new InvalidArgumentException("El plano {$shot->order} no tiene description.");
        }

        if ($shot->isOutro || $shot->isIntro) {
            return implode(', ', array_values(array_filter(
                [
                    $description,
                    $this->styleSuffix,
                    $this->channelNegatives(),
                ],
                static fn (string $part): bool => $part !== '',
            )));
        }

        $parts = [$this->part(self::RANK_DESCRIPTION, $this->sanitize($description))];

        if ($shot->subject === 'threat') {
            $parts[] = $this->part(self::RANK_THREAT, $this->sanitize($this->threatStageDescriptor($bible, $shot->threatStage)));
            // El ente sale ocultado siempre, sin excepción: un plano con alguien dentro y sin
            // encoding de ocultación es exactamente el que devuelve una cara a medio resolver.
            $parts[] = $this->part(self::RANK_THREAT, $this->occlusion($shot));
        }

        // El tramo dice dónde está plantada la cámara y la luz cuánta queda: son los dos bloques
        // que hacen que cien planos se lean como un recorrido y no como un carrusel del mismo
        // sitio. Por eso van por encima del setting, que a partir de aquí es solo el ancla corta.
        $parts[] = $this->part(self::RANK_JOURNEY, $this->sanitize($bible->journeyDescriptor($shot->journeyLeg)));
        $parts[] = $this->part(self::RANK_JOURNEY, $this->sanitize($this->light($bible, $shot)));
        $parts[] = $this->part(self::RANK_SETTING, $this->sanitize($bible->setting));
        $parts[] = $this->part(self::RANK_REST, $this->sanitize($shot->framing));
        $parts[] = $this->part(self::RANK_REST, $this->sanitize($bible->weather));
        $parts[] = $this->part(self::RANK_REST, $this->sanitize(implode(' ', $bible->palette)));

        // El tope gobierna solo la parte descriptiva. Los negativos son la única defensa contra
        // caras resueltas y marcas de agua, y el sufijo es lo que mantiene el estilo constante
        // entre planos: ninguno de los dos compite por el sitio de lo que describe la escena.
        $prompt = array_values(array_filter(
            [
                $this->descriptive($this->dedupe($parts), $this->maxWords),
                $this->styleSuffix,
                $this->negatives($bible),
            ],
            static fn (string $part): bool => $part !== '',
        ));

        return implode(', ', $prompt);
    }

    /**
     * @param  list<Shot>  $shots
     * @return list<string>
     */
    public function previewAll(array $shots, VisualBible $bible): array
    {
        $prompts = [];

        foreach ($shots as $shot) {
            $prompts[] = $this->build($shot, $bible);
        }

        return $prompts;
    }

    /**
     * @return array{rank: int, text: string}
     */
    private function part(int $rank, string $text): array
    {
        return [
            'rank' => $rank,
            'text' => trim($text),
        ];
    }

    /**
     * Quita los bloques repetidos antes de presupuestar, sin distinguir mayúsculas: la biblia y el
     * planificador comparten vocabulario y el mismo texto puede llegar por dos vías. Gana la
     * primera aparición, que es la de mayor prioridad.
     *
     * @param  list<array{rank: int, text: string}>  $parts
     * @return list<array{rank: int, text: string}>
     */
    private function dedupe(array $parts): array
    {
        $kept = [];
        $seen = [];

        foreach ($parts as $part) {
            $key = mb_strtolower($part['text']);

            if ($part['text'] === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $part;
        }

        return $kept;
    }

    private function occlusion(Shot $shot): string
    {
        return self::OCCLUSIONS[$shot->order % count(self::OCCLUSIONS)];
    }

    /**
     * La etapa de luz del plano, y si el plano no trae ninguna resoluble, la hora que la biblia
     * dejó fijada. Un shots.json dirigido antes de que existieran las etapas no se queda sin luz:
     * se queda con la de siempre.
     */
    private function light(VisualBible $bible, Shot $shot): string
    {
        $stage = $bible->lightDescriptor($shot->lightStage);

        return $stage === '' ? $bible->timeOfDay : $stage;
    }

    /**
     * Reparte el presupuesto de palabras entre los bloques descriptivos: cada bloque elige antes
     * que los de menor prioridad, y el que no quepa se descarta entero. En cuanto se cae un bloque
     * por falta de sitio, nada de menor prioridad puede colarse detrás: si no, el descriptor del
     * ente acaba cediendo su hueco a la paleta y al clima. Solo el primero (la
     * description del plano) se recorta, porque un prompt sin él no describe nada. El prompt se
     * devuelve en orden semántico, no en orden de prioridad.
     *
     * @param  list<array{rank: int, text: string}>  $parts
     */
    private function descriptive(array $parts, int $budget): string
    {
        if ($budget <= 0) {
            return '';
        }

        $order = array_keys($parts);
        usort(
            $order,
            static fn (int $left, int $right): int => [$parts[$left]['rank'], $left] <=> [$parts[$right]['rank'], $right],
        );

        $kept = [];
        $spent = 0;
        $droppedRank = null;

        foreach ($order as $index) {
            $text = $parts[$index]['text'];
            $rank = $parts[$index]['rank'];

            if ($text === '' || ($droppedRank !== null && $rank > $droppedRank)) {
                continue;
            }

            $cost = $this->wordCount($text);

            if ($spent + $cost > $budget) {
                if ($kept !== []) {
                    $droppedRank = $droppedRank === null ? $rank : min($droppedRank, $rank);

                    continue;
                }

                $text = $this->limitWords($text, $budget);
                $cost = $budget;
            }

            $kept[$index] = $text;
            $spent += $cost;
        }

        ksort($kept);

        return implode(', ', $kept);
    }

    private function threatStageDescriptor(VisualBible $bible, ?string $stage): string
    {
        if ($stage === null || $stage === '') {
            return '';
        }

        foreach ($bible->threat['stages'] as $item) {
            if ($item['stage'] === $stage) {
                return $item['descriptor'];
            }
        }

        return '';
    }

    /**
     * Negativos fijos del canal. La careta y el outro no suman los de la biblia: si no, el prompt
     * cambiaría con cada historia y sus imágenes no reutilizarían caché.
     */
    private function channelNegatives(): string
    {
        return implode(', ', [
            'no text',
            'no watermark',
            'no logos',
            'no clear facial features',
            'no direct eye contact',
        ]);
    }

    private function negatives(VisualBible $bible): string
    {
        $items = [
            'no text',
            'no watermark',
            'no logos',
            'no clear facial features',
            'no direct eye contact',
        ];
        $seen = array_map(static fn (string $item): string => mb_strtolower($item), $items);

        foreach ($bible->avoid as $item) {
            $item = $this->sanitize($item);

            if ($item === '') {
                continue;
            }

            $clause = str_starts_with(mb_strtolower($item), 'no ') ? $item : 'no '.$item;
            $key = mb_strtolower($clause);

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $items[] = $clause;
        }

        return implode(', ', $items);
    }

    private function sanitize(string $text): string
    {
        return $this->stripProperNames($this->soften($text));
    }

    private function soften(string $text): string
    {
        $replacements = self::EUPHEMISMS;
        uksort($replacements, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        foreach ($replacements as $from => $to) {
            $text = (string) preg_replace('/\b'.preg_quote($from, '/').'\b/iu', $to, $text);
        }

        return trim($text);
    }

    private function stripProperNames(string $text): string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];

        foreach ($words as $index => $word) {
            $plain = trim($word, '.,;:!?');

            if ($index > 0 && preg_match('/^\p{Lu}\p{L}{2,}$/u', $plain) === 1) {
                continue;
            }

            $kept[] = $word;
        }

        return implode(' ', $kept);
    }

    private function limitWords(string $prompt, int $max): string
    {
        $words = $this->words($prompt);

        if (count($words) <= $max) {
            return $prompt;
        }

        return implode(' ', array_slice($words, 0, $max));
    }

    private function wordCount(string $text): int
    {
        return count($this->words($text));
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }
}
