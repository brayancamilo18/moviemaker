<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class Shot
{
    /**
     * @param  list<string>  $characterSlugs
     */
    public function __construct(
        public int $order,
        public int $sceneOrder,
        public float $start,
        public float $end,
        public string $sourceText,
        public string $framing,
        public string $motion,
        public string $subject,
        public ?string $threatStage,
        public string $description = '',
        /** @var list<string> */
        public array $characterSlugs = [],
        public ?string $imagePath = null,
    ) {}

    /**
     * @param  array{
     *     order: int,
     *     sceneOrder: int,
     *     start?: float,
     *     end?: float,
     *     sourceText?: string,
     *     framing?: string,
     *     motion?: string,
     *     subject?: string,
     *     threatStage?: string|null,
     *     description?: string,
     *     characterSlugs?: list<string>,
     *     imagePath?: string|null
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $threat = $data['threatStage'] ?? null;
        $slugs = [];

        foreach (is_array($data['characterSlugs'] ?? null) ? $data['characterSlugs'] : [] as $slug) {
            $slug = trim((string) $slug);

            if ($slug !== '' && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        $imagePath = $data['imagePath'] ?? null;

        return new self(
            order: (int) $data['order'],
            sceneOrder: (int) $data['sceneOrder'],
            start: (float) ($data['start'] ?? 0),
            end: (float) ($data['end'] ?? 0),
            sourceText: is_string($data['sourceText'] ?? null) ? $data['sourceText'] : '',
            framing: is_string($data['framing'] ?? null) ? $data['framing'] : '',
            motion: is_string($data['motion'] ?? null) ? $data['motion'] : 'static',
            subject: is_string($data['subject'] ?? null) ? $data['subject'] : '',
            threatStage: is_string($threat) && $threat !== '' ? $threat : null,
            description: trim((string) ($data['description'] ?? '')),
            characterSlugs: $slugs,
            imagePath: is_string($imagePath) && $imagePath !== '' ? $imagePath : null,
        );
    }

    /**
     * @return array{
     *     order: int,
     *     sceneOrder: int,
     *     start: float,
     *     end: float,
     *     sourceText: string,
     *     framing: string,
     *     motion: string,
     *     subject: string,
     *     threatStage: ?string,
     *     description: string,
     *     characterSlugs: list<string>,
     *     imagePath: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'sceneOrder' => $this->sceneOrder,
            'start' => $this->start,
            'end' => $this->end,
            'sourceText' => $this->sourceText,
            'framing' => $this->framing,
            'motion' => $this->motion,
            'subject' => $this->subject,
            'threatStage' => $this->threatStage,
            'description' => $this->description,
            'characterSlugs' => $this->characterSlugs,
            'imagePath' => $this->imagePath,
        ];
    }
}
