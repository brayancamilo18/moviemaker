<?php

declare(strict_types=1);

namespace App\Enums;

enum StoryStatus: string
{
    case Draft = 'borrador';
    case ScriptReady = 'guion listo';
    case Narrated = 'narrada';
    case ImagesReady = 'imagenes listas';
    case Mixed = 'mezclada';
    case Rendered = 'renderizada';
    case PendingReview = 'pendiente de revision';
    case ReadyToPublish = 'lista para publicar';
    case Downloaded = 'descargada';
    case Published = 'publicada';
    case Discarded = 'descartada';
    case Failed = 'fallida';

    /**
     * @var array<string, list<self>>
     */
    private const TRANSITIONS = [
        'borrador' => [self::ScriptReady, self::Failed, self::Discarded],
        'guion listo' => [self::Narrated, self::Failed, self::Discarded, self::Draft],
        'narrada' => [self::ImagesReady, self::Failed, self::Discarded, self::Draft, self::ScriptReady],
        'imagenes listas' => [self::Mixed, self::Failed, self::Discarded, self::Draft, self::ScriptReady, self::Narrated],
        'mezclada' => [self::Rendered, self::Failed, self::Discarded, self::Draft, self::ScriptReady, self::Narrated, self::ImagesReady],
        'renderizada' => [self::PendingReview, self::Failed, self::Discarded, self::Draft, self::ScriptReady, self::Narrated, self::ImagesReady, self::Mixed],
        'pendiente de revision' => [self::ReadyToPublish, self::Discarded, self::ScriptReady],
        'lista para publicar' => [self::Downloaded, self::Discarded],
        'descargada' => [self::Published, self::Discarded],
        'publicada' => [],
        'descartada' => [],
        'fallida' => [
            self::Draft,
            self::ScriptReady,
            self::Narrated,
            self::ImagesReady,
            self::Mixed,
            self::Rendered,
            self::Discarded,
        ],
    ];

    public function label(): string
    {
        return match ($this) {
            self::ImagesReady => 'imágenes listas',
            self::PendingReview => 'pendiente de revisión',
            default => $this->value,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft, self::ScriptReady => '#6E6C68',
            self::Narrated, self::ImagesReady, self::Mixed => '#7D8A99',
            self::Rendered => '#9B99C4',
            self::PendingReview => '#E2A044',
            self::ReadyToPublish, self::Downloaded, self::Published => '#4FA265',
            self::Discarded => '#5A4340',
            self::Failed => '#D24A3C',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Published || $this === self::Discarded;
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, self::TRANSITIONS[$this->value], true);
    }
}
