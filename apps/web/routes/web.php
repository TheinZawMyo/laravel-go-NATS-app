<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\InvitePageController;
use App\Http\Controllers\ModerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('chats/{conversation?}', ChatController::class)->name('chats.index');

    Route::get('join/{token}', InvitePageController::class)->name('join');

    Route::get('moderation', ModerationController::class)
        ->middleware('admin')
        ->name('moderation.index');
});

require __DIR__.'/settings.php';
