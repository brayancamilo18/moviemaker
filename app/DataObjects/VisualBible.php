<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class VisualBible
{
    /**
     * @param  list<string>  $palette
     * @param  list<array{slug: string, descriptor: string, framingRule: string}>  $characters
     * @param  list<array{slug: string, descriptor: string}>  $recurringObjects
     * @param  list<string>  $avoid
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
        );
    }

    /**
     * @return array{setting: string, era: string, timeOfDay: string, weather: string, palette: list<string>, characters: list<array{slug: string, descriptor: string, framingRule: string}>, recurringObjects: list<array{slug: string, descriptor: string}>, avoid: list<string>}
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
     * @return list<array{slug: string, descriptor: string, framingRule: string}>
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
            $descriptor = (string) ($item['descriptor'] ?? '');
            $framingRule = (string) ($item['framingRule'] ?? '');

            if ($slug === '' || $descriptor === '' || $framingRule === '') {
                continue;
            }

            $characters[] = [
                'slug' => $slug,
                'descriptor' => $descriptor,
                'framingRule' => $framingRule,
            ];
        }

        return $characters;
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
}
