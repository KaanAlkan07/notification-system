<?php

use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\TwilioReceiptController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(public_path('console/index.html')));

Route::get('/health', [OperationsController::class, 'health']);
Route::get('/metrics', [OperationsController::class, 'metrics'])->middleware('api.token');
Route::post('/webhooks/twilio/sms/{id}/{generation}/{attempt}', TwilioReceiptController::class)
    ->whereUuid('id')->whereNumber('generation')->where('attempt', '[1-9][0-9]*');

Route::middleware('api.token')->prefix('api/v1')->group(function () {
    Route::get('notification-trends', [OperationsController::class, 'trends']);
    Route::post('notifications', [NotificationController::class, 'store']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/{id}', [NotificationController::class, 'show'])->whereUuid('id');
    Route::post('notifications/{id}/cancel', [NotificationController::class, 'cancel'])->whereUuid('id');
    Route::post('notifications/{id}/retry', [NotificationController::class, 'retry'])->whereUuid('id');
    Route::post('notification-batches', [NotificationController::class, 'storeBatch'])->name('batches.store');
    Route::get('notification-batches/{id}', [NotificationController::class, 'showBatch'])->whereUuid('id');
});
