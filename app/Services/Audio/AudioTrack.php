<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\SoundCredit;
use InvalidArgumentException;

final readonly class AudioTrack
{
    public const ROLE_NARRATION = 'narration';

    public const ROLE_AMBIENCE = 'ambience';

    public const ROLE_SFX = 'sfx';

    public const ROLE_MUSIC = 'music';

    /**
     * @var list<string>
     */
    public const ROLES = [
        self::ROLE_NARRATION,
        self::ROLE_AMBIENCE,
        self::ROLE_SFX,
        self::ROLE_MUSIC,
    ];

    /**
     * `credits` es la procedencia de lo que suena en esta pista. Es una lista porque una sola
     * pista puede venir de varios clips: la cama de ambiente funde uno por escena en un único WAV
     * y ahí ya no se puede deducir el origen a partir de `path`.
     *
     * @param  list<SoundCredit>  $credits
     */
    public function __construct(
        public string $path,
        public string $role,
        public float $startAt,
        public ?float $endAt,
        public float $gainDb,
        public bool $duckable,
        public float $fadeIn,
        public float $fadeOut,
        public array $credits = [],
    ) {
        foreach ($this->credits as $credit) {
            if (! $credit instanceof SoundCredit) {
                throw new InvalidArgumentException('credits solo acepta instancias de SoundCredit.');
            }
        }

        if (! in_array($this->role, self::ROLES, true)) {
            throw new InvalidArgumentException(
                "El rol '{$this->role}' no es válido. Usa narration, ambience, sfx o music.",
            );
        }

        if ($this->path === '') {
            throw new InvalidArgumentException('La pista no tiene path.');
        }

        if ($this->startAt < 0) {
            throw new InvalidArgumentException('startAt no puede ser negativo.');
        }

        if ($this->endAt !== null && $this->endAt <= $this->startAt) {
            throw new InvalidArgumentException('endAt debe ser posterior a startAt.');
        }

        if ($this->fadeIn < 0 || $this->fadeOut < 0) {
            throw new InvalidArgumentException('fadeIn y fadeOut no pueden ser negativos.');
        }
    }
}
