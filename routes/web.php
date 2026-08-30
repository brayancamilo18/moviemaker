<?php

declare(strict_types=1);

use App\Http\Controllers\StoryController;
use App\Http\Controllers\StoryInspectionController;
use App\Http\Controllers\StoryMediaController;
use App\Services\Llm\ProviderHealth;
use App\Services\Pipeline\QueueHealth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/queue');

Route::get('/queue', function (QueueHealth $queue) {
    return Inertia::render('Queue', [
        'queue' => $queue->status(),
    ]);
})->name('queue');
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
Route::post('/llm/health', function (ProviderHealth $health) {
    return response()->json($health->check(live: true));
})->name('llm.health');
Route::get('/pipeline', function (QueueHealth $queue) {
    return Inertia::render('Pipeline', [
        'queue' => $queue->status(),
    ]);
})->name('pipeline');
Route::redirect('/review', '/queue')->name('review');
Route::get('/sheet', fn () => Inertia::render('ContactSheet'))->name('sheet');
Route::get('/thumbnail', fn () => Inertia::render('Thumbnail'))->name('thumbnail');
Route::get('/package', fn () => Inertia::render('Package'))->name('package');

Route::get('/media/{story}/{artifact}', [StoryMediaController::class, 'show'])
    ->where('artifact', '[A-Za-z0-9._-]+')
    ->name('story.media');
