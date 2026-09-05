<?php

declare(strict_types=1);

use App\Http\Controllers\ContactSheetController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\StoryInspectionController;
use App\Http\Controllers\StoryMediaController;
use App\Http\Controllers\ThumbnailController;
use App\Jobs\CheckProviderHealth;
use App\Services\Llm\ProviderHealthStore;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/queue');

Route::get('/queue', [QueueController::class, 'index'])->name('queue');
Route::get('/stories/create', [StoryController::class, 'create'])->name('stories.create');
Route::post('/stories', [StoryController::class, 'store'])->name('stories.store');
Route::get('/pipeline/state', [PipelineController::class, 'state'])->name('pipeline.state');
Route::get('/pipeline', [PipelineController::class, 'index'])->name('pipeline');
Route::get('/stories/{story:id}/pipeline', [PipelineController::class, 'show'])->name('pipeline.show');
Route::get('/stories/{story:id}/progress', [StoryController::class, 'progress'])->name('stories.progress');
Route::post('/stories/{story:id}/retry', [StoryController::class, 'retry'])->name('stories.retry');
Route::post('/stories/{story:id}/continue', [StoryController::class, 'continuePipeline'])->name('stories.continue');
Route::post('/stories/{story:id}/discard', [StoryController::class, 'discard'])->name('stories.discard');
Route::post('/stories/{story}/review-again', [StoryController::class, 'reviewAgain'])->name('stories.review_again');
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
Route::get('/review', [StoryController::class, 'reviewEntry'])->name('review');
Route::get('/sheet', [ContactSheetController::class, 'entry'])->name('sheet');
Route::get('/stories/{story}/sheet', [ContactSheetController::class, 'show'])->name('sheet.show');
Route::get('/stories/{story}/shots/{order}/image', [ContactSheetController::class, 'image'])
    ->whereNumber('order')
    ->name('sheet.image');
Route::get('/thumbnail', [ThumbnailController::class, 'entry'])->name('thumbnail');
Route::get('/stories/{story}/thumbnail', [ThumbnailController::class, 'show'])->name('thumbnail.show');
Route::post('/stories/{story}/thumbnail', [ThumbnailController::class, 'store'])->name('thumbnail.store');
Route::post('/stories/{story}/thumbnail/{thumbnail}/select', [ThumbnailController::class, 'select'])
    ->name('thumbnail.select');
Route::delete('/stories/{story}/thumbnail/{thumbnail}', [ThumbnailController::class, 'destroy'])
    ->name('thumbnail.destroy');
Route::get('/stories/{story}/thumbnail/{thumbnail}/download', [ThumbnailController::class, 'download'])
    ->whereNumber('thumbnail')
    ->name('thumbnail.download');
Route::get('/stories/{story}/thumbnail/{order}/image', [ThumbnailController::class, 'image'])
    ->whereNumber('order')
    ->name('thumbnail.image');
Route::get('/package', fn () => Inertia::render('Package'))->name('package');

Route::get('/media/{story}/{artifact}', [StoryMediaController::class, 'show'])
    ->where('artifact', '[A-Za-z0-9._-]+')
    ->name('story.media');
