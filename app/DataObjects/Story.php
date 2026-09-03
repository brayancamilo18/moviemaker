<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class Story
{
    /**
     * @param  list<string>  $tags
     * @param  list<StoryScene>  $scenes
     * @param  list<Pronunciation>  $pronunciations
     */
    public function __construct(
        public string $title,
        public string $hook,
        public string $description,
        public array $tags,
        public string $thumbnailPrompt,
        public array $scenes,
        public array $pronunciations,
        public ?StoryScene $coldOpen = null,
        public string $hookLine = '',
        public ?VisualBible $visualBible = null,
    ) {}

    /**
     * coldOpen y hookLine llegan vacíos en los guiones escritos antes de que existieran: son
     * opcionales a propósito, porque el arranque no puede invalidar una historia ya generada.
     *
     * @param  array{title: string, hook: string, description: string, tags: list<string>, thumbnailPrompt: string, scenes: list<array{order: int, narration: string, imagePrompt?: string, visualSummary?: string, ambience?: array<string, mixed>|null}>, pronunciations?: list<array{term: string, phonetic: string}>, coldOpen?: array{narration?: string, visualSummary?: string}|null, hookLine?: string, visualBible?: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'],
            hook: $data['hook'],
            description: $data['description'],
            tags: $data['tags'],
            thumbnailPrompt: $data['thumbnailPrompt'],
            scenes: array_map(
                static fn (array $scene): StoryScene => StoryScene::fromArray($scene),
                $data['scenes'],
            ),
            pronunciations: array_map(
                static fn (array $pronunciation): Pronunciation => Pronunciation::fromArray($pronunciation),
                $data['pronunciations'] ?? [],
            ),
            coldOpen: self::coldOpenFromArray($data['coldOpen'] ?? null),
            hookLine: trim((string) ($data['hookLine'] ?? '')),
            visualBible: isset($data['visualBible']) && is_array($data['visualBible'])
                ? VisualBible::fromArray($data['visualBible'])
                : null,
        );
    }

    /**
     * El cold open llega sin orden de escena: el suyo lo pone la configuración, y quien lo pide
     * lo sella con `coldOpenScene()`. Guardarlo aquí obligaría a leer config desde un DataObject.
     */
    private static function coldOpenFromArray(mixed $data): ?StoryScene
    {
        if (! is_array($data)) {
            return null;
        }

        $narration = trim((string) ($data['narration'] ?? ''));

        if ($narration === '') {
            return null;
        }

        return new StoryScene(
            order: 0,
            narration: $narration,
            imagePrompt: '',
            visualSummary: trim((string) ($data['visualSummary'] ?? '')),
        );
    }

    /**
     * @return array{title: string, hook: string, description: string, tags: list<string>, thumbnailPrompt: string, scenes: list<array{order: int, narration: string, imagePrompt: string, visualSummary: string, ambience: ?array{query: string, tags: list<string>, intensity: string}}>, pronunciations: list<array{term: string, phonetic: string}>, coldOpen?: array{narration: string, visualSummary: string}, hookLine?: string, visualBible?: array{setting: string, era: string, timeOfDay: string, weather: string, palette: list<string>, journey: list<array{slug: string, descriptor: string}>, light: list<array{slug: string, descriptor: string}>, recurringObjects: list<array{slug: string, descriptor: string}>, avoid: list<string>, threat: array{nature: string, stages: list<array{stage: string, descriptor: string}>}}}
     */
    public function toArray(): array
    {
        $payload = [
            'title' => $this->title,
            'hook' => $this->hook,
            'description' => $this->description,
            'tags' => $this->tags,
            'thumbnailPrompt' => $this->thumbnailPrompt,
            'scenes' => array_map(
                static fn (StoryScene $scene): array => $scene->toArray(),
                $this->scenes,
            ),
            'pronunciations' => array_map(
                static fn (Pronunciation $pronunciation): array => $pronunciation->toArray(),
                $this->pronunciations,
            ),
        ];

        if ($this->coldOpen instanceof StoryScene) {
            $payload['coldOpen'] = [
                'narration' => $this->coldOpen->narration,
                'visualSummary' => $this->coldOpen->visualSummary,
            ];
        }

        if ($this->hookLine !== '') {
            $payload['hookLine'] = $this->hookLine;
        }

        if ($this->visualBible instanceof VisualBible) {
            $payload['visualBible'] = $this->visualBible->toArray();
        }

        return $payload;
    }

    public function withVisualBible(VisualBible $visualBible): self
    {
        return new self(
            title: $this->title,
            hook: $this->hook,
            description: $this->description,
            tags: $this->tags,
            thumbnailPrompt: $this->thumbnailPrompt,
            scenes: $this->scenes,
            pronunciations: $this->pronunciations,
            coldOpen: $this->coldOpen,
            hookLine: $this->hookLine,
            visualBible: $visualBible,
        );
    }

    /**
     * El cold open como escena de pleno derecho, sellado con el orden que le da la configuración.
     */
    public function coldOpenScene(int $order): ?StoryScene
    {
        if (! $this->coldOpen instanceof StoryScene) {
            return null;
        }

        return new StoryScene(
            order: $order,
            narration: $this->coldOpen->narration,
            imagePrompt: $this->coldOpen->imagePrompt,
            visualSummary: $this->coldOpen->visualSummary,
            ambience: $this->coldOpen->ambience,
        );
    }

    /**
     * Escenas que cuentan historia: el cold open y las del guion. La careta y el cierre no están
     * aquí porque son texto fijo del canal, no narración de esta historia.
     *
     * @return list<StoryScene>
     */
    public function narrativeScenes(int $coldOpenOrder): array
    {
        $coldOpen = $this->coldOpenScene($coldOpenOrder);

        return $coldOpen instanceof StoryScene
            ? [$coldOpen, ...$this->scenes]
            : $this->scenes;
    }

    public function sceneByOrder(int $order, int $coldOpenOrder): ?StoryScene
    {
        foreach ($this->narrativeScenes($coldOpenOrder) as $scene) {
            if ($scene->order === $order) {
                return $scene;
            }
        }

        return null;
    }

    public function wordCount(): int
    {
        $narration = implode(' ', array_map(
            static fn (StoryScene $scene): string => $scene->narration,
            $this->scenes,
        ));

        $words = preg_split('/\s+/u', trim($narration), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }

    public function estimatedDurationSeconds(): int
    {
        return (int) round($this->wordCount() / 130 * 60);
    }

    public function narrationForTts(): string
    {
        return implode(' ', array_map(
            fn (StoryScene $scene): string => $this->textForTts($scene->narration),
            $this->scenes,
        ));
    }

    /**
     * Narración del guion partida por escena, sin fonética: es lo que se publica en timings.json.
     *
     * @return list<array{order: int, text: string}>
     */
    public function scenesForNarration(): array
    {
        return array_map(
            static fn (StoryScene $scene): array => [
                'order' => $scene->order,
                'text' => $scene->narration,
            ],
            $this->scenes,
        );
    }

    /**
     * Escenas a narrar, con el cierre fijo del canal al final si está activo.
     *
     * @return list<array{order: int, text: string}>
     */
    public function scenesForNarrationWithOutro(string $outroText, int $outroOrder): array
    {
        return $this->scenesForNarrationWithBookends(null, '', 0, $outroText, $outroOrder);
    }

    /**
     * La narración entera en orden de vídeo: cold open, careta, historia y cierre.
     *
     * Un bloque con el texto vacío no se narra, y ese es el único interruptor: quien apaga el
     * arranque desde la configuración pasa el texto vacío o el orden nulo, y aquí no hay ninguna
     * otra rama que mantener.
     *
     * @param  int|null  $coldOpenOrder  Orden del cold open, o null para no narrarlo
     * @return list<array{order: int, text: string}>
     */
    public function scenesForNarrationWithBookends(
        ?int $coldOpenOrder,
        string $introTemplate,
        int $introOrder,
        string $outroText,
        int $outroOrder,
    ): array {
        $scenes = [];
        $coldOpen = $coldOpenOrder === null ? null : $this->coldOpenScene($coldOpenOrder);

        if ($coldOpen instanceof StoryScene) {
            $scenes[] = ['order' => $coldOpen->order, 'text' => $coldOpen->narration];
        }

        $intro = $this->introNarration($introTemplate);

        if ($intro !== '') {
            $scenes[] = ['order' => $introOrder, 'text' => $intro];
        }

        foreach ($this->scenesForNarration() as $scene) {
            $scenes[] = $scene;
        }

        if (trim($outroText) !== '') {
            $scenes[] = ['order' => $outroOrder, 'text' => trim($outroText)];
        }

        return $scenes;
    }

    /**
     * La careta: presentación fija del canal más la frase gancho que el LLM escribió para esta
     * historia. Sin plantilla no hay careta, aunque haya gancho: el gancho solo no se sostiene.
     */
    public function introNarration(string $template): string
    {
        $template = trim($template);

        if ($template === '') {
            return '';
        }

        return trim($template.' '.$this->hookLine);
    }

    /**
     * Misma fonética que narrationForTts(), partida por escena para conservar pausas.
     *
     * @return list<array{order: int, text: string}>
     */
    public function scenesForTts(): array
    {
        return array_map(
            fn (StoryScene $scene): array => [
                'order' => $scene->order,
                'text' => $this->textForTts($scene->narration),
            ],
            $this->scenes,
        );
    }

    public function textForTts(string $text): string
    {
        $pronunciations = $this->pronunciations;

        usort(
            $pronunciations,
            static fn (Pronunciation $left, Pronunciation $right): int => mb_strlen($right->term) <=> mb_strlen($left->term),
        );

        foreach ($pronunciations as $pronunciation) {
            if ($pronunciation->term === '') {
                continue;
            }

            $text = str_replace($pronunciation->term, $pronunciation->phonetic, $text);
        }

        return $text;
    }
}
