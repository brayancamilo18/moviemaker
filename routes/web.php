<?php

declare(strict_types=1);

use App\Http\Controllers\QueueController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\StoryInspectionController;
use App\Http\Controllers\StoryMediaController;
use App\Jobs\CheckProviderHealth;
use App\Services\Llm\ProviderHealthStore;
use App\Services\Pipeline\QueueHealth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/queue');

Route::get('/queue', [QueueController::class, 'index'])->name('queue');
Route::get('/stories/create', [StoryController::class, 'create'])->name('stories.create');
Route::post('/stories', [StoryController::class, 'store'])->name('stories.store');
Route::get('/stories/{story:id}/pipeline', [StoryController::class, 'pipeline'])->name('pipeline.show');
Route::get('/stories/{story:id}/progress', [StoryController::class, 'progress'])->name('stories.progress');
Route::post('/stories/{story:id}/retry', [StoryController::class, 'retry'])->name('stories.retry');
Route::post('/stories/{story:id}/continue', [StoryController::class, 'continuePipeline'])->name('stories.continue');
Route::post('/stories/{story:id}/discard', [StoryController::class, 'discard'])->name('stories.discard');
Route::get('/stories/{story}/inspection/script', [StoryInspectionController::class, 'script'])
    ->name('stories.inspection.script');
Route::get('/stories/{story}/review', [StoryController::class, 'review'])->name('review.show');
Route::post('/llm/health', function (ProviderHealthStore $store) {
    CheckProviderHealth::dispatch();

    return response()->json(['queued' => true, 'last' => $store->get()]);
})->name('llm.health.check');
Route::get('/llm/health', function (ProviderHealthStore $store) {
    return response()->json($store->get() ?? ['report' => null]);
})->name('llm.health');
Route::get('/pipeline', function (QueueHealth $queue) {
    return Inertia::render('Pipeline', [
        'queue' => $queue->status(),
    ]);
})->name('pipeline');
Route::get('/review', [StoryController::class, 'reviewEntry'])->name('review');
Route::get('/sheet', fn () => Inertia::render('ContactSheet'))->name('sheet');
Route::get('/thumbnail', fn () => Inertia::render('Thumbnail'))->name('thumbnail');
Route::get('/package', fn () => Inertia::render('Package'))->name('package');

Route::get('/media/{story}/{artifact}', [StoryMediaController::class, 'show'])
    ->where('artifact', '[A-Za-z0-9._-]+')
    ->name('story.media');
