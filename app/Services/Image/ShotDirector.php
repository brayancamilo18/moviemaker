<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\Contracts\JsonLlm;
use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\DataObjects\StoryScene;
use App\DataObjects\VisualBible;
use App\Exceptions\LlmGenerationException;
use App\Services\Llm\LlmTask;
use Illuminate\Contracts\Config\Repository;
use JsonException;

final class ShotDirector
{
    /**
     * No hay 'protagonist' ni 'both': la cámara es el oyente, así que el narrador nunca está en
     * cuadro y el ente es la única figura que puede aparecer.
     *
     * @var list<string>
     */
    private const SUBJECTS = [
        'threat',
        'environment',
        'detail',
    ];

    /**
     * Sin 'over the shoulder': ese encuadre exige un hombro en primer plano, y el único cuerpo
     * que podría ser es el del narrador, que no existe.
     *
     * @var list<string>
     */
    private const FRAMINGS = [
        'wide establishing',
        'medium shot',
        'close detail',
        'low angle',
        'extreme close up',
    ];

    /**
     * Intentos de dirección por escena. El primero es estricto y le devuelve al modelo qué falló;
     * el último corrige lo que quede en vez de tirar la escena.
     */
    private const ATTEMPTS = 2;

    /**
     * @var list<string>
     */
    private const THREAT_STAGES = [
        'hint',
        'presence',
        'reveal',
    ];

    /**
     * Encuadres que a una figura le exigen una cara resuelta, y el que se usa en su lugar. El
     * proveedor gratuito no la resuelve a la resolución que entrega, así que no se le pide: se
     * corrige aquí y queda escrito en shots.json, en vez de confiar en que el modelo obedezca
     * la regla de escala del prompt.
     *
     * @var array<string, string>
     */
    private const FIGURE_FRAMING_FALLBACK = [
        'extreme close up' => 'low angle',
    ];

    /**
     * Encuadres en los que la cosa enfocada llena el cuadro, y por tanto los únicos en los que una
     * cara tiene píxeles para salir resuelta.
     *
     * @var list<string>
     */
    private const FACE_SAFE_FRAMINGS = [
        'close detail',
        'extreme close up',
    ];

    /**
     * Palabras que hacen que el proveedor intente una cara o una mano.
     *
     * No están prohibidas: están racionadas por escala. Con la cabeza a 140-190 px el proveedor
     * tiene píxeles para intentar una cara y no los suficientes para acertarla, y eso es lo que
     * devuelve caras derretidas a media distancia; llenando el cuadro sí la resuelve. Así que una
     * de estas palabras solo pasa si el encuadre es de los que llenan el cuadro, y en cualquier
     * otro se rechaza.
     *
     * Solo partes que el modelo resuelve mal y que además leen como persona. Fuera quedan las que
     * dan falsos positivos con objetos de attrezzo: 'chest' es un arcón, 'palm' una palmera y
     * 'arms' un arma.
     *
     * @var list<string>
     */
    private const HUMAN_ANATOMY = [
        'face', 'faces', 'facial', 'eye', 'eyes', 'eyelid', 'eyelids', 'eyebrow', 'eyebrows',
        'mouth', 'mouths', 'lip', 'lips', 'teeth', 'tooth', 'tongue', 'cheek', 'cheeks',
        'chin', 'jaw', 'nose', 'nostril', 'nostrils', 'forehead', 'hand', 'hands',
        'finger', 'fingers', 'fingertip', 'fingertips', 'fingernail', 'fingernails',
        'thumb', 'thumbs', 'knuckle', 'knuckles', 'wrist', 'wrists', 'skin', 'flesh',
    ];

    private readonly int $threatRatioMin;

    private readonly int $threatRatioMax;

    private readonly int $detailRatioMax;

    private readonly int $outroSceneOrder;

    private readonly string $outroImagePrompt;

    public function __construct(
        private JsonLlm $llm,
        Repository $config,
    ) {
        $this->threatRatioMin = $this->percent($config->get('stories.images.direction.threat_ratio_min'));
        $this->threatRatioMax = $this->percent($config->get('stories.images.direction.threat_ratio_max'));
        $this->detailRatioMax = $this->percent($config->get('stories.images.direction.detail_ratio_max'));
        $this->outroSceneOrder = (int) $config->get('stories.story.outro.scene_order');
        $this->outroImagePrompt = trim((string) $config->get('stories.story.outro.image_prompt'));
    }

    private function percent(mixed $ratio): int
    {
        return (int) round(((float) $ratio) * 100);
    }

    /**
     * @param  list<Shot>  $shots
     * @return list<Shot> los mismos planos con description, subject, threatStage, framing, journeyLeg y lightStage dirigidos
     */
    public function direct(array $shots, Story $story, VisualBible $bible): array
    {
        if ($shots === []) {
            return [];
        }

        $sceneCount = max(count($story->scenes), 1);
        $directedByOrder = [];

        // Suelo del recorrido y de la luz: el índice más avanzado que ya se ha usado. Las escenas
        // se recorren en orden, así que arrastrarlo entre ellas es lo que impide que el trayecto
        // desande camino o que la luz vuelva a abrirse a mitad del vídeo.
        $floors = ['journey' => 0, 'light' => 0];

        foreach ($this->groupByScene($shots) as $sceneOrder => $sceneShots) {
            if ($sceneOrder === $this->outroSceneOrder) {
                foreach ($sceneShots as $shot) {
                    $directedByOrder[$shot->order] = $this->directOutro($shot);
                }

                continue;
            }

            foreach ($this->directScene($sceneShots, $story, $bible, $sceneOrder, $sceneCount, $floors) as $shot) {
                $directedByOrder[$shot->order] = $shot;
            }
        }

        $directed = [];

        foreach ($shots as $shot) {
            if (! isset($directedByOrder[$shot->order])) {
                throw new LlmGenerationException(
                    "La dirección de la escena {$shot->sceneOrder} no devolvió el plano {$shot->order}.",
                );
            }

            $directed[] = $directedByOrder[$shot->order];
        }

        return $directed;
    }

    /**
     * El cierre del canal no se dirige: prompt fijo, plano único, fuera de Gemini.
     */
    private function directOutro(Shot $shot): Shot
    {
        return new Shot(
            order: $shot->order,
            sceneOrder: $shot->sceneOrder,
            start: $shot->start,
            end: $shot->end,
            sourceText: $shot->sourceText,
            framing: 'wide establishing',
            motion: $shot->motion,
            subject: $shot->subject,
            threatStage: $shot->threatStage,
            journeyLeg: $shot->journeyLeg,
            lightStage: $shot->lightStage,
            description: $this->outroImagePrompt,
            characterSlugs: [],
            imagePath: $shot->imagePath,
            isOutro: true,
        );
    }

    /**
     * @param  list<Shot>  $shots
     * @return array<int, list<Shot>>
     */
    private function groupByScene(array $shots): array
    {
        $groups = [];

        foreach ($shots as $shot) {
            $groups[$shot->sceneOrder][] = $shot;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * @param  list<Shot>  $sceneShots
     * @param  array{journey: int, light: int}  $floors
     * @return list<Shot>
     */
    private function directScene(
        array $sceneShots,
        Story $story,
        VisualBible $bible,
        int $sceneOrder,
        int $sceneCount,
        array &$floors,
    ): array {
        $expected = array_map(static fn (Shot $shot): int => $shot->order, $sceneShots);
        $progress = $sceneOrder / $sceneCount;
        $ceilings = [
            'journey' => $this->ceiling($bible->journeySlugs(), $progress),
            'light' => $this->ceiling($bible->lightSlugs(), $progress),
        ];
        $schema = $this->schema($bible, $floors, $ceilings);
        $lastError = $this->mismatchMessage($sceneOrder, $expected, []);

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            // El reintento lleva dentro por qué se rechazó el anterior. Sin eso solo sería volver a
            // tirar el dado a temperatura 0.7, y el modelo repetiría la misma pega.
            $userPrompt = $this->userPrompt(
                $sceneShots,
                $story,
                $bible,
                $sceneOrder,
                $progress,
                $floors,
                $ceilings,
                $attempt === 0 ? null : $lastError,
            );

            $data = $this->llm->generateJson(
                $this->systemInstruction(),
                $userPrompt,
                $schema,
                task: LlmTask::ShotDirection,
                temperature: 0.7,
            );

            // Sobre una copia: un intento que se caiga a medias no puede dejar el suelo del
            // recorrido movido para el reintento.
            $advanced = $floors;

            // En el último intento ya no se reintenta nada: lo que no cumpla se corrige aquí. Un
            // plano con una pega no puede tirar la escena entera después de haber avisado una vez.
            $lenient = $attempt === self::ATTEMPTS - 1;

            try {
                $directed = $this->hydrateScene($sceneShots, $data, $expected, $sceneOrder, $bible, $advanced, $ceilings, $lenient);
            } catch (LlmGenerationException $exception) {
                $lastError = $exception->getMessage();

                continue;
            }

            $floors = $advanced;

            return $directed;
        }

        throw new LlmGenerationException($lastError);
    }

    /**
     * @param  list<Shot>  $sceneShots
     * @param  array<string, mixed>  $data
     * @param  list<int>  $expected
     * @param  array{journey: int, light: int}  $floors
     * @param  array{journey: int, light: int}  $ceilings
     * @return list<Shot>
     */
    private function hydrateScene(
        array $sceneShots,
        array $data,
        array $expected,
        int $sceneOrder,
        VisualBible $bible,
        array &$floors,
        array $ceilings,
        bool $lenient,
    ): array {
        $rows = is_array($data['shots'] ?? null) ? $data['shots'] : [];
        $received = [];
        $byIndex = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! array_key_exists('shotIndex', $row)) {
                continue;
            }

            $shotIndex = (int) $row['shotIndex'];
            $received[] = $shotIndex;
            $byIndex[$shotIndex] = $row;
        }

        $mismatch = $this->indexMismatch($expected, $received);

        if ($mismatch !== null) {
            throw new LlmGenerationException($this->mismatchMessage($sceneOrder, $expected, $received, $mismatch));
        }

        $directed = [];

        foreach ($sceneShots as $shot) {
            $directed[] = $this->applyDirection(
                $shot,
                $byIndex[$shot->order],
                $sceneOrder,
                $expected,
                $received,
                $bible,
                $floors,
                $ceilings,
                $lenient,
            );
        }

        return $directed;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<int>  $expected
     * @param  list<int>  $received
     * @param  array{journey: int, light: int}  $floors
     * @param  array{journey: int, light: int}  $ceilings
     */
    private function applyDirection(
        Shot $shot,
        array $row,
        int $sceneOrder,
        array $expected,
        array $received,
        VisualBible $bible,
        array &$floors,
        array $ceilings,
        bool $lenient,
    ): Shot {
        $subject = trim((string) ($row['subject'] ?? ''));

        if (! in_array($subject, self::SUBJECTS, true)) {
            throw new LlmGenerationException(
                $this->mismatchMessage(
                    $sceneOrder,
                    $expected,
                    $received,
                    "Subject no válido en el plano {$shot->order}: '{$subject}'.",
                ),
            );
        }

        $framing = trim((string) ($row['framing'] ?? ''));

        if (! in_array($framing, self::FRAMINGS, true)) {
            throw new LlmGenerationException(
                $this->mismatchMessage(
                    $sceneOrder,
                    $expected,
                    $received,
                    "Framing no válido en el plano {$shot->order}: '{$framing}'.",
                ),
            );
        }

        $framing = $this->figureSafeFraming($framing, $subject);

        $description = trim((string) ($row['description'] ?? ''));

        if ($description === '') {
            throw new LlmGenerationException(
                $this->mismatchMessage(
                    $sceneOrder,
                    $expected,
                    $received,
                    "El plano {$shot->order} llegó sin description.",
                ),
            );
        }

        $threat = trim((string) ($row['threatStage'] ?? ''));
        $threatStage = in_array($threat, self::THREAT_STAGES, true) ? $threat : null;

        if ($subject !== 'threat') {
            $threatStage = null;
        }

        $journeyLeg = $this->advance($bible->journeySlugs(), $row['journeyLeg'] ?? null, $floors['journey'], $ceilings['journey']);
        $lightStage = $this->advance($bible->lightSlugs(), $row['lightStage'] ?? null, $floors['light'], $ceilings['light']);
        // El ente va siempre ocultado, así que su descripción no pasa por aquí. Y una cara que
        // llena el cuadro tampoco: ahí el proveedor tiene píxeles de sobra y sale nítida.
        $anatomy = $subject === 'threat' || in_array($framing, self::FACE_SAFE_FRAMINGS, true)
            ? null
            : $this->anatomyIn($description);

        if ($anatomy !== null) {
            if (! $lenient) {
                throw new LlmGenerationException(
                    $this->mismatchMessage(
                        $sceneOrder,
                        $expected,
                        $received,
                        "El plano {$shot->order} nombra anatomía humana ('{$anatomy}') con encuadre '{$framing}', "
                        .'que la deja demasiado pequeña para resolverla. O lo describes por lo que la luz toca, '
                        .'con el cuerpo fuera de cuadro, o subes a extreme close up y que llene el cuadro.',
                    ),
                );
            }

            // Segundo intento y sigue habiendo anatomía: se degrada a un plano del sitio. Un plano
            // de paisaje de más es aburrido; una cara a medio resolver arruina el vídeo entero.
            $subject = 'environment';
            $threatStage = null;
            $description = $bible->journeyDescriptor($journeyLeg) ?: $bible->setting;
        }

        return new Shot(
            order: $shot->order,
            sceneOrder: $shot->sceneOrder,
            start: $shot->start,
            end: $shot->end,
            sourceText: $shot->sourceText,
            framing: $framing,
            motion: $shot->motion,
            subject: $subject,
            threatStage: $threatStage,
            journeyLeg: $journeyLeg,
            lightStage: $lightStage,
            description: $description,
            characterSlugs: $shot->characterSlugs,
            imagePath: $shot->imagePath,
            isOutro: $shot->isOutro,
        );
    }

    /**
     * La primera palabra de anatomía humana de la descripción, o null si no hay ninguna.
     *
     * Con límites de palabra, que es lo que separa 'hand' de 'handle' y 'skin' de 'skinny'.
     */
    private function anatomyIn(string $description): ?string
    {
        $pattern = '/\b('.implode('|', self::HUMAN_ANATOMY).')\b/iu';

        if (preg_match($pattern, $description, $matches) !== 1) {
            return null;
        }

        return mb_strtolower($matches[1]);
    }

    /**
     * Hasta dónde puede haber llegado el recorrido a estas alturas de la historia.
     *
     * El suelo protege de desandar camino, pero solo con él una escena que se adelanta empuja el
     * recorrido hasta el último tramo y lo congela ahí el resto del vídeo: el paseo se acaba en el
     * primer tercio y la voz sigue hablando de sitios que la imagen ya dejó atrás. Así que el
     * tramo también se acota por arriba, y el techo lo pone el avance de la historia.
     *
     * @param  list<string>  $slugs
     */
    private function ceiling(array $slugs, float $progress): int
    {
        if ($slugs === []) {
            return 0;
        }

        $last = count($slugs) - 1;

        return max(0, min((int) ceil($progress * count($slugs)) - 1, $last));
    }

    /**
     * Resuelve el tramo (o la etapa de luz) que pidió el modelo y deja el suelo donde toque.
     *
     * Nunca lanza: un slug desconocido o anterior al suelo se queda en el suelo, y uno que se
     * adelanta a la historia se recorta al techo en vez de caerse. Recortar y no caerse importa:
     * si un director que pide el final en cada escena volviera al suelo cada vez, el paseo no
     * arrancaría nunca; recortado, avanza un tramo por escena, que es el paso correcto.
     *
     * @param  list<string>  $slugs
     */
    private function advance(array $slugs, mixed $requested, int &$floor, int $ceiling): ?string
    {
        if ($slugs === []) {
            return null;
        }

        $index = min($floor, $ceiling);
        $position = array_search(is_string($requested) ? trim($requested) : '', $slugs, true);

        if (is_int($position) && $position > $index) {
            $index = min($position, $ceiling);
        }

        $floor = $index;

        return $slugs[$index];
    }

    private function figureSafeFraming(string $framing, string $subject): string
    {
        if ($subject !== 'threat') {
            return $framing;
        }

        return self::FIGURE_FRAMING_FALLBACK[$framing] ?? $framing;
    }

    /**
     * @param  list<int>  $expected
     * @param  list<int>  $received
     */
    private function indexMismatch(array $expected, array $received): ?string
    {
        $missing = array_values(array_diff($expected, $received));
        $extra = array_values(array_diff($received, $expected));
        $counts = array_count_values($received);
        $duplicates = [];

        foreach ($counts as $index => $count) {
            if ($count > 1) {
                $duplicates[] = $index;
            }
        }

        if (count($received) === count($expected) && $missing === [] && $extra === [] && $duplicates === []) {
            return null;
        }

        $parts = [
            'Faltan índices: '.$this->indexList($missing).'.',
            'Sobran índices: '.$this->indexList($extra).'.',
        ];

        if ($duplicates !== []) {
            $parts[] = 'Repetidos: '.$this->indexList($duplicates).'.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<int>  $expected
     * @param  list<int>  $received
     */
    private function mismatchMessage(
        int $sceneOrder,
        array $expected,
        array $received,
        ?string $detail = null,
    ): string {
        $mismatch = $this->indexMismatch($expected, $received)
            ?? ('Faltan índices: ninguno. Sobran índices: ninguno.');
        $suffix = ($detail !== null && $detail !== $mismatch) ? ' '.$detail : '';

        return "La dirección de la escena {$sceneOrder} no es válida. {$mismatch}{$suffix}";
    }

    /**
     * @param  list<int>  $indices
     */
    private function indexList(array $indices): string
    {
        if ($indices === []) {
            return 'ninguno';
        }

        return implode(', ', array_map(static fn (int $index): string => (string) $index, $indices));
    }

    /**
     * @param  list<Shot>  $sceneShots
     * @param  array{journey: int, light: int}  $floors
     * @param  array{journey: int, light: int}  $ceilings
     */
    private function userPrompt(
        array $sceneShots,
        Story $story,
        VisualBible $bible,
        int $sceneOrder,
        float $progress,
        array $floors,
        array $ceilings,
        ?string $previousError = null,
    ): string {
        $scene = $this->scene($story, $sceneOrder);
        $journeySlugs = $bible->journeySlugs();
        $lightSlugs = $bible->lightSlugs();
        $shots = [];

        foreach ($sceneShots as $shot) {
            $shots[] = [
                'shotIndex' => $shot->order,
                'durationSeconds' => round(max(0.0, $shot->end - $shot->start), 3),
                'narration' => $shot->sourceText,
            ];
        }

        try {
            return json_encode(
                [
                    'bible' => $bible->toArray(),
                    'sceneSummary' => $scene?->visualSummary ?? '',
                    'sceneNarration' => $scene?->narration ?? '',
                    'storyProgress' => $progress,
                    // Solo la ventana: del tramo donde ya va el paseo hasta donde la historia
                    // permite llegar. Ni lo ya consumido ni lo que aún no toca.
                    'journeyOptions' => $this->window($journeySlugs, $floors['journey'], $ceilings['journey']),
                    'suggestedJourneyLeg' => $journeySlugs[$ceilings['journey']] ?? null,
                    'lightOptions' => $this->window($lightSlugs, $floors['light'], $ceilings['light']),
                    'suggestedLightStage' => $lightSlugs[$ceilings['light']] ?? null,
                    ...($previousError === null ? [] : ['previousAttemptError' => $previousError]),
                    'shots' => $shots,
                ],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new LlmGenerationException(
                "No se pudo serializar el prompt de dirección de la escena {$sceneOrder}.",
                previous: $exception,
            );
        }
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function window(array $slugs, int $floor, int $ceiling): array
    {
        if ($slugs === []) {
            return [];
        }

        return array_values(array_slice($slugs, $floor, max(1, $ceiling - $floor + 1)));
    }

    private function scene(Story $story, int $order): ?StoryScene
    {
        foreach ($story->scenes as $scene) {
            if ($scene->order === $order) {
                return $scene;
            }
        }

        return null;
    }

    private function systemInstruction(): string
    {
        $threatMin = $this->threatRatioMin;
        $threatMax = $this->threatRatioMax;
        $detailMax = $this->detailRatioMax;

        return <<<INSTRUCTION
You are the shot director for a horror YouTube channel. You do not write story. You decide what the camera sees in each individual shot of one scene.

THE CAMERA IS THE LISTENER. The narration is first person and the camera stands where that person's eyes are, walking their route. The narrator is never in frame: no back, no shoulder, no hands, no arms, no reflection of the narrator. If the line says a hand rests on a latch, the image is the latch, wet and close, and the hand is out of frame. If the line says she stopped at the treeline, the image is the treeline seen from where the walking stopped.

Everyone else in the script is shown by what they leave behind, never as a body: a door left open, a chair pushed back, a car parked with its lights on, boots by the step, a plate half eaten. Traces, not people. The ONE figure that may appear in frame is the threat, and only when subject is threat.

A HUMAN FACE OR HAND IS A MATTER OF SCALE, and this is the rule that decides whether an image works. The provider has just enough pixels to attempt a head and not enough to get it right: a face at walking or conversational distance comes back melted and ruins the shot, while a face that fills the frame comes back sharp.

So when subject is not threat, your description may name a part of a human body (face, eyes, mouth, cheek, hand, finger, wrist, skin) on ONE condition: framing must be extreme close up or close detail, and that part must be the only thing in frame, close enough to count eyelashes. At wide establishing, medium shot or low angle, naming a body part is checked word by word and thrown straight back at you.

At those wider framings the answer is always the same: describe what the light falls on instead. 'His face went pale as he counted' becomes candlelight on a stone wall with a shadow crossing it. 'He reached out and took the candle' becomes the taper held out in the fog with nothing yet holding it. 'His skin touched the wax' becomes the wick flaring white for an instant. The line still lands, because the voice already said whose face it was. Choose the close framing and show the face only when the narration is genuinely about that face and nothing else will do.

THE CORE RULE: each shot has its own narration line. Your description must show what THAT line describes, at that moment. Never describe the scene in general. Never describe something that happens in a different shot.

THE JOURNEY: the video is one continuous walk. journeyLeg says where the camera is standing, and you pick it from journeyOptions and nowhere else. journeyOptions is a window: it starts where the walk already is and ends at suggestedJourneyLeg, which is as far as the story has got by now. Take the suggestion unless this scene's narration has clearly not moved yet, in which case stay where you are. You cannot run ahead of the narration and you cannot double back, and both are already impossible in journeyOptions: burning through the whole route early would leave the rest of the video standing in a place the voice stopped talking about.

THE LIGHT: lightStage says how much light is left, and you pick it from lightOptions, which is the same kind of window. It only ever closes down over the video and never reopens, and suggestedLightStage is as dark as the story has earned so far. Late shots are darker than early ones; that is the whole point.

CONTINUITY: consecutive shots share leg, light, palette and weather unless the narration explicitly moves. Use the bible verbatim for setting, era, palette, journey and light descriptors. Never contradict the bible. Never include anything from bible.avoid. Reuse bible.recurringObjects as landmarks so the route reads as one place: meeting the same gatepost from a new distance is worth more than a new invention.

SUBJECT DISTRIBUTION across the shots of this scene:
- Between {$threatMin} and {$threatMax} percent may be threat. The upper bound is a limit, not a target: an entity that appears in one shot out of three stops lurking.
- Up to {$detailMax} percent may be detail.
- The rest is environment: the place ahead, around and underfoot. Vary it. Two environment shots in a row must not share framing.

SCALE. This one is not negotiable, because it is what separates an image that works from one that falls apart:
- close detail and extreme close up are for one thing filling the frame, and never for the threat. Usually an object: a latch, a boot print in mud, a wet doorknob, a moth on glass, a hem caught on wire. A face, only under the scale rule above.
- When subject is threat, keep the camera far enough that the shape reads and the head does not: a distant figure, a silhouette, a mass filling one side of the frame. Never centre a head at conversational distance.

THREAT ESCALATION, driven by storyProgress:
- Below 0.33: only hint. The threat may be present but ambiguous, distant, or possibly imagined.
- 0.33 to 0.70: presence. Unmistakably there but incomplete: partial, at a distance, just outside the light.
- Above 0.70: reveal is allowed, and a reveal is a complete silhouette: the whole shape against a light source, seen from low, dominating the frame by sheer size. Terror comes from scale and backlight, never from a head near the camera.
- Never use a later stage than storyProgress permits.

HARD LIMITS: no proper names. No text or logos in the image. No face at all except under the scale rule, and never on the threat. No gore: write blood as dark stain, a corpse as still figure. Descriptions are static stills, not actions in progress.

If previousAttemptError is present, your previous answer was rejected for exactly that reason. Fix that and only that, and keep everything else you had.

Return one entry per shot you received, with the same shotIndex values. Never merge, skip, or invent shots.
INSTRUCTION;
    }

    /**
     * El enum de tramo y de luz se recorta al vuelo con lo que queda por delante: así el propio
     * proveedor ya no puede devolver un tramo que el recorrido dejó atrás.
     *
     * @param  array{journey: int, light: int}  $floors
     * @param  array{journey: int, light: int}  $ceilings
     * @return array<string, mixed>
     */
    private function schema(VisualBible $bible, array $floors, array $ceilings): array
    {
        $properties = [
            'shotIndex' => ['type' => 'INTEGER', 'description' => 'Echo the shotIndex you were given.'],
            'description' => ['type' => 'STRING', 'description' => 'The image for THIS shot narration, in English, 8 to 14 words. Static still, seen from where the listener stands. No narrator body, no proper names, no gore, no resolved faces.'],
            // Los vocabularios cerrados van como enum, no solo en la descripción:
            // un modelo que se invente un valor obliga a reintentar la escena
            // entera, y hay proveedores que se lo inventan.
            'subject' => [
                'type' => 'STRING',
                'description' => 'What the image is about. Only threat puts a figure in frame.',
                'enum' => self::SUBJECTS,
            ],
            'threatStage' => [
                'type' => 'STRING',
                // 'none' y no la cadena vacía: Gemini rechaza un enum con un
                // valor vacío. Cualquier valor fuera de THREAT_STAGES se hidrata
                // como null, así que 'none' significa exactamente eso.
                'description' => 'How much of the threat is visible. Use none when subject is not threat.',
                'enum' => [...self::THREAT_STAGES, 'none'],
            ],
            'framing' => [
                'type' => 'STRING',
                'description' => 'Shot scale for this image.',
                'enum' => self::FRAMINGS,
            ],
        ];

        $required = ['shotIndex', 'description', 'subject', 'threatStage', 'framing'];

        $journey = $this->window($bible->journeySlugs(), $floors['journey'], $ceilings['journey']);

        if ($journey !== []) {
            $properties['journeyLeg'] = [
                'type' => 'STRING',
                'description' => 'Where the camera stands for this shot. Only where the walk already is and how far the story lets it go.',
                'enum' => $journey,
            ];
            $required[] = 'journeyLeg';
        }

        $light = $this->window($bible->lightSlugs(), $floors['light'], $ceilings['light']);

        if ($light !== []) {
            $properties['lightStage'] = [
                'type' => 'STRING',
                'description' => 'How much light is left in this shot. The light only closes down, and never faster than the story.',
                'enum' => $light,
            ];
            $required[] = 'lightStage';
        }

        return [
            'type' => 'OBJECT',
            'properties' => [
                'shots' => [
                    'type' => 'ARRAY',
                    'description' => 'One entry per received shot, same order, same shotIndex values.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => $properties,
                        'required' => $required,
                    ],
                ],
            ],
            'required' => ['shots'],
        ];
    }
}
