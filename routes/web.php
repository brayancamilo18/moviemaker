<?php

use App\Http\Controllers\StoryMediaController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/queue');

Route::get('/queue', fn () => Inertia::render('Queue'))->name('queue');
Route::get('/stories/create', fn () => Inertia::render('NewStory'))->name('stories.create');
Route::get('/pipeline', fn () => Inertia::render('Pipeline'))->name('pipeline');
Route::get('/review', fn () => Inertia::render('Review'))->name('review');
Route::get('/sheet', fn () => Inertia::render('ContactSheet'))->name('sheet');
Route::get('/thumbnail', fn () => Inertia::render('Thumbnail'))->name('thumbnail');
Route::get('/package', fn () => Inertia::render('Package'))->name('package');

Route::get('/media/{story}/{artifact}', [StoryMediaController::class, 'show'])
    ->where('artifact', '[A-Za-z0-9._-]+')
    ->name('story.media');
