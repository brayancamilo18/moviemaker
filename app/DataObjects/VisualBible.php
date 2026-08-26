<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class VisualBible
{
    /**
     * @param  list<string>  $palette
     * @param  list<array{slug: string, bodyDescriptor: string, framingOptions: list<string>}>  $characters
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
        public array $characters,
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
            characters: self::characterList($data['characters'] ?? []),
            recurringObjects: self::objectList($data['recurringObjects'] ?? []),
            avoid: self::stringList($data['avoid'] ?? []),
            threat: self::threat($data['threat'] ?? []),
        );
    }

    /**
     * @return array{setting: string, era: string, timeOfDay: string, weather: string, palette: list<string>, characters: list<array{slug: string, bodyDescriptor: string, framingOptions: list<string>}>, recurringObjects: list<array{slug: string, descriptor: string}>, avoid: list<string>, threat: array{nature: string, stages: list<array{stage: string, descriptor: string}>}}
     */
    public function toArray(): array
    {
        return [
            'setting' => $this->setting,
            'era' => $this->era,
            'timeOfDay' => $this->timeOfDay,
            'weather' => $this->weather,
            'palette' => $this->palette,
            'characters' => $this->characters,
            'recurringObjects' => $this->recurringObjects,
            'avoid' => $this->avoid,
            'threat' => $this->threat,
        ];
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
     * @return list<array{slug: string, bodyDescriptor: string, framingOptions: list<string>}>
     */
    private static function characterList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $characters = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = (string) ($item['slug'] ?? '');
            $bodyDescriptor = (string) ($item['bodyDescriptor'] ?? $item['descriptor'] ?? '');
            $framingOptions = self::framingOptions($item);

            if ($slug === '' || $bodyDescriptor === '') {
                continue;
            }

            $characters[] = [
                'slug' => $slug,
                'bodyDescriptor' => $bodyDescriptor,
                'framingOptions' => $framingOptions,
            ];
        }

        return $characters;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private static function framingOptions(array $item): array
    {
        if (isset($item['framingOptions']) && is_array($item['framingOptions'])) {
            return self::stringList($item['framingOptions']);
        }

        $legacy = (string) ($item['framingRule'] ?? '');

        return $legacy === '' ? [] : [$legacy];
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
