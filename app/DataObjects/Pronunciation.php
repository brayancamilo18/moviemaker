<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class Pronunciation
{
    public function __construct(
        public string $term,
        public string $phonetic,
    ) {}

    /**
     * @param  array{term: string, phonetic: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            term: $data['term'],
            phonetic: $data['phonetic'],
        );
    }

    /**
     * @return array{term: string, phonetic: string}
     */
    public function toArray(): array
    {
        return [
            'term' => $this->term,
            'phonetic' => $this->phonetic,
        ];
    }
}
