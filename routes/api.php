<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\NotificationBatchController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['correlation.id'])->group(function () {
    Route::get('health', HealthController::class)->name('api.health');

    Route::middleware(['api.key'])->group(function (): void {
        Route::get('metrics', MetricsController::class)->name('api.metrics');

        Route::post('notifications', [NotificationController::class, 'store'])->name('api.notifications.store');
        Route::get('notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
        Route::get('notifications/{notification}', [NotificationController::class, 'show'])->name('api.notifications.show');
        Route::post('notifications/{notification}/cancel', [NotificationController::class, 'cancel'])->name('api.notifications.cancel');
        Route::post('notifications/{notification}/retry', [NotificationController::class, 'retry'])->name('api.notifications.retry');

        Route::post('notification-batches', [NotificationBatchController::class, 'store'])->name('api.notification-batches.store');
        Route::get('notification-batches/{notificationBatch}', [NotificationBatchController::class, 'show'])->name('api.notification-batches.show');
        Route::post('notification-batches/{notificationBatch}/retry', [NotificationBatchController::class, 'retry'])->name('api.notification-batches.retry');
    });
});
