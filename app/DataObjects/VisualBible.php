<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class VisualBible
{
    /**
     * @param  list<string>  $palette
     * @param  list<array{slug: string, descriptor: string}>  $journey
     * @param  list<array{slug: string, descriptor: string}>  $light
     * @param  list<array{slug: string, descriptor: string}>  $recurringObjects
     * @param  list<string>  $avoid
     * @param  array{nature: string, stages: list<array{stage: string, descriptor: string}>}  $threat
     */
    public function __construct(
        public string $setting,
        public string $era,
        public string $timeOfDay,
        public string $weather,
        public array $palette,
        public array $journey,
        public array $light,
        public array $recurringObjects,
        public array $avoid,
        public array $threat,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            setting: (string) ($data['setting'] ?? ''),
            era: (string) ($data['era'] ?? ''),
            timeOfDay: (string) ($data['timeOfDay'] ?? ''),
            weather: (string) ($data['weather'] ?? ''),
            palette: self::stringList($data['palette'] ?? []),
            journey: self::objectList($data['journey'] ?? []),
            light: self::objectList($data['light'] ?? []),
            recurringObjects: self::objectList($data['recurringObjects'] ?? []),
            avoid: self::stringList($data['avoid'] ?? []),
            threat: self::threat($data['threat'] ?? []),
        );
    }

    /**
     * @return array{setting: string, era: string, timeOfDay: string, weather: string, palette: list<string>, journey: list<array{slug: string, descriptor: string}>, light: list<array{slug: string, descriptor: string}>, recurringObjects: list<array{slug: string, descriptor: string}>, avoid: list<string>, threat: array{nature: string, stages: list<array{stage: string, descriptor: string}>}}
     */
    public function toArray(): array
    {
        return [
            'setting' => $this->setting,
            'era' => $this->era,
            'timeOfDay' => $this->timeOfDay,
            'weather' => $this->weather,
            'palette' => $this->palette,
            'journey' => $this->journey,
            'light' => $this->light,
            'recurringObjects' => $this->recurringObjects,
            'avoid' => $this->avoid,
            'threat' => $this->threat,
        ];
    }

    /**
     * Slugs de los tramos, en el orden del recorrido. El índice es la posición en el trayecto, y
     * es lo que permite comprobar que un plano nunca retrocede respecto al anterior.
     *
     * @return list<string>
     */
    public function journeySlugs(): array
    {
        return self::slugsOf($this->journey);
    }

    /**
     * Slugs de las etapas de luz, de la más abierta a la más cerrada.
     *
     * @return list<string>
     */
    public function lightSlugs(): array
    {
        return self::slugsOf($this->light);
    }

    public function journeyDescriptor(?string $slug): string
    {
        return self::descriptorOf($this->journey, $slug);
    }

    public function lightDescriptor(?string $slug): string
    {
        return self::descriptorOf($this->light, $slug);
    }

    /**
     * @param  list<array{slug: string, descriptor: string}>  $items
     * @return list<string>
     */
    private static function slugsOf(array $items): array
    {
        return array_values(array_map(
            static fn (array $item): string => $item['slug'],
            $items,
        ));
    }

    /**
     * @param  list<array{slug: string, descriptor: string}>  $items
     */
    private static function descriptorOf(array $items, ?string $slug): string
    {
        if ($slug === null || $slug === '') {
            return '';
        }

        foreach ($items as $item) {
            if ($item['slug'] === $slug) {
                return $item['descriptor'];
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<array{slug: string, descriptor: string}>
     */
    private static function objectList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $objects = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = (string) ($item['slug'] ?? '');
            $descriptor = (string) ($item['descriptor'] ?? '');

            if ($slug === '' || $descriptor === '') {
                continue;
            }

            $objects[] = [
                'slug' => $slug,
                'descriptor' => $descriptor,
            ];
        }

        return $objects;
    }

    /**
     * @return array{nature: string, stages: list<array{stage: string, descriptor: string}>}
     */
    private static function threat(mixed $value): array
    {
        if (! is_array($value)) {
            return [
                'nature' => '',
                'stages' => [],
            ];
        }

        $byStage = [];
        $raw = $value['stages'] ?? [];

        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $stage = strtolower((string) ($item['stage'] ?? ''));
                $descriptor = (string) ($item['descriptor'] ?? '');

                if (! in_array($stage, ['hint', 'presence', 'reveal'], true) || $descriptor === '') {
                    continue;
                }

                $byStage[$stage] = [
                    'stage' => $stage,
                    'descriptor' => $descriptor,
                ];
            }
        }

        $stages = [];

        foreach (['hint', 'presence', 'reveal'] as $stage) {
            if (isset($byStage[$stage])) {
                $stages[] = $byStage[$stage];
            }
        }

        return [
            'nature' => (string) ($value['nature'] ?? ''),
            'stages' => $stages,
        ];
    }
}
