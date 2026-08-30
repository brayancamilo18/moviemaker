<?php

use App\Http\Controllers\StoryMediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/media/{story}/{artifact}', [StoryMediaController::class, 'show'])
    ->where('artifact', '[A-Za-z0-9._-]+')
    ->name('story.media');
