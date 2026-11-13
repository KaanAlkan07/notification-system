<?php

use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OperationsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [OperationsController::class, 'health']);
Route::get('/metrics', [OperationsController::class, 'metrics'])->middleware('api.token');

Route::middleware('api.token')->prefix('api/v1')->group(function () {
    Route::post('notifications', [NotificationController::class, 'store']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/{id}', [NotificationController::class, 'show'])->whereUuid('id');
    Route::post('notifications/{id}/cancel', [NotificationController::class, 'cancel'])->whereUuid('id');
    Route::post('notification-batches', [NotificationController::class, 'storeBatch'])->name('batches.store');
    Route::get('notification-batches/{id}', [NotificationController::class, 'showBatch'])->whereUuid('id');
});
