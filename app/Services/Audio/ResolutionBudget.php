<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * Relojes y contadores de la resolución de sonidos: el tiempo que se concede a la historia
 * completa, el que se concede a cada señal y los intentos de red que admite una señal.
 */
final class ResolutionBudget
{
    private const MAX_ATTEMPTS_PER_SIGNAL = 8;

    private readonly float $signalBudgetSeconds;

    private readonly float $storyBudgetSeconds;

    private float $storyStartedAt = 0.0;

    private float $signalStartedAt = 0.0;

    private int $signalAttempts = 0;

    private bool $storyWarned = false;

    public function __construct(
        private LoggerInterface $logger,
        Repository $config,
    ) {
        $this->signalBudgetSeconds = max(0.0, (float) $config->get('stories.audio.resolve_budget_seconds', 20));
        $this->storyBudgetSeconds = max(0.0, (float) $config->get('stories.audio.resolve_total_budget_seconds', 600));
    }

    /**
     * El reloj de la historia arranca con la primera señal y no se reinicia.
     */
    public function beginStory(): void
    {
        if ($this->storyStartedAt <= 0.0) {
            $this->storyStartedAt = microtime(true);
        }
    }

    public function beginSignal(): void
    {
        $this->signalStartedAt = microtime(true);
        $this->signalAttempts = 0;
    }

    public function recordAttempt(): void
    {
        $this->signalAttempts++;
    }

    public function allowsNetwork(): bool
    {
        if ($this->storyExceeded()) {
            $this->warnStoryOnce();

            return false;
        }

        if ($this->signalExceeded()) {
            return false;
        }

        return $this->signalAttempts < self::MAX_ATTEMPTS_PER_SIGNAL;
    }

    private function storyExceeded(): bool
    {
        if ($this->storyStartedAt <= 0.0) {
            return false;
        }

        return (microtime(true) - $this->storyStartedAt) >= $this->storyBudgetSeconds;
    }

    private function signalExceeded(): bool
    {
        if ($this->signalStartedAt <= 0.0) {
            return false;
        }

        return (microtime(true) - $this->signalStartedAt) >= $this->signalBudgetSeconds;
    }

    private function warnStoryOnce(): void
    {
        if ($this->storyWarned) {
            return;
        }

        $this->storyWarned = true;
        $this->logger->warning('Presupuesto de resolución de la historia agotado; el resto se resuelve sin red.');
    }
}
