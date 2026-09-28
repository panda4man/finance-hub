<?php

use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:api', 'auth:sanctum'])
    ->prefix('transactions')
    ->name('api.transactions.')
    ->group(function () {
        Route::get('/', [TransactionController::class, 'index'])
            ->middleware('abilities:viewAny')
            ->name('index');
        Route::get('/{transaction}', [TransactionController::class, 'show'])
            ->middleware('abilities:view')
            ->whereUuid('transaction')
            ->name('show');
    });
