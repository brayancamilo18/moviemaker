<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Llm\SpendReport;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly SpendReport $spend,
    ) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $month = $this->spend->forMonth();

        return [
            ...parent::share($request),
            'pendingReviewCount' => Story::query()
                ->where('status', StoryStatus::PendingReview)
                ->count(),
            'monthlySpend' => $month['euro'],
            'monthlySpendTitle' => $month['title'],
        ];
    }
}
