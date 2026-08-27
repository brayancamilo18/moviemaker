<?php

declare(strict_types=1);

namespace App\DataObjects;

/**
 * Procedencia del clip que suena de verdad en una pista de la mezcla.
 *
 * Los tres placers resuelven en tiempo de mezcla cuando el fichero del cue no sirve, así que lo
 * que acaba en el vídeo puede no ser lo que declara sounds.json. Esto describe lo que sí suena,
 * con la misma forma que una entrada de sounds.json para que quien acredite no tenga que
 * distinguir el origen.
 */
final readonly class SoundCredit
{
    public function __construct(
        public string $cueId,
        public string $type,
        public string $role,
        public string $file,
        public ?string $author = null,
        public ?string $license = null,
        public ?string $sourceUrl = null,
        public bool $attributionRequired = false,
    ) {}

    /**
     * Clip resuelto en tiempo de mezcla: la licencia la trae el resolver.
     *
     * `$file` sobrescribe la ruta cuando lo que suena no es lo que devolvió el resolver, como la
     * cama de ambiente que cae a un WAV sintetizado.
     */
    public static function fromResolved(
        string $cueId,
        string $type,
        string $role,
        ResolvedSound $sound,
        ?string $file = null,
    ): self {
        return new self(
            cueId: $cueId,
            type: $type,
            role: $role,
            file: $file ?? $sound->path,
            author: $sound->author,
            license: $sound->license,
            sourceUrl: $sound->sourceUrl,
            attributionRequired: $sound->attributionRequired,
        );
    }

    /**
     * Clip del que el placer solo conoce la ruta, porque venía como override de un cue. La
     * licencia vive en ese cue y quien acredita la recupera emparejando por ruta.
     */
    public static function fromOverride(string $cueId, string $type, string $role, string $file): self
    {
        return new self(
            cueId: $cueId,
            type: $type,
            role: $role,
            file: $file,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCue(): array
    {
        return [
            'id' => $this->cueId,
            'type' => $this->type,
            'role' => $this->role,
            'file' => $this->file,
            'author' => $this->author,
            'license' => $this->license,
            'sourceUrl' => $this->sourceUrl,
            'attributionRequired' => $this->attributionRequired,
        ];
    }
}
