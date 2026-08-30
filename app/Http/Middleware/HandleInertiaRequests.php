<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\StoryStatus;
use App\Models\Story;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'pendingReviewCount' => Story::query()
                ->where('status', StoryStatus::PendingReview)
                ->count(),
            'monthlySpend' => $this->monthlySpend(),
        ];
    }

    private function monthlySpend(): string
    {
        $sum = (float) Story::query()
            ->whereBetween('created_at', [
                now()->copy()->startOfMonth(),
                now()->copy()->endOfMonth(),
            ])
            ->sum('llm_cost_usd');

        return number_format($sum, 2, ',', '').' €';
    }
}
