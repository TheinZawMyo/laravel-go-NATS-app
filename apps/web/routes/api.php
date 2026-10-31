<?php

use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\GatewayTokenController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('v1')->group(function () {
    Route::get('me', [MeController::class, 'show'])->name('api.v1.me');

    Route::get('gateway/token', GatewayTokenController::class)->name('api.v1.gateway.token');

    Route::get('users/{username}', [UserController::class, 'show'])->name('api.v1.users.show');

    Route::get('devices', [DeviceController::class, 'index'])->name('api.v1.devices.index');
    Route::post('devices', [DeviceController::class, 'store'])->name('api.v1.devices.store');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->name('api.v1.devices.destroy');

    Route::get('conversations', [ConversationController::class, 'index'])->name('api.v1.conversations.index');
    Route::post('conversations/direct', [ConversationController::class, 'storeDirect'])->name('api.v1.conversations.store-direct');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('api.v1.conversations.show');
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->name('api.v1.conversations.read');

    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])->name('api.v1.messages.index');
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])->name('api.v1.messages.store');
    Route::patch('messages/{message}', [MessageController::class, 'update'])->name('api.v1.messages.update');
    Route::delete('messages/{message}', [MessageController::class, 'destroy'])->name('api.v1.messages.destroy');
});
