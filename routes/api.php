<?php

use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:api', 'auth:sanctum', 'abilities:transactions:read'])
    ->prefix('transactions')
    ->name('api.transactions.')
    ->group(function () {
        Route::get('/', [TransactionController::class, 'index'])->name('index');
        Route::get('/{transaction}', [TransactionController::class, 'show'])
            ->whereUuid('transaction')
            ->name('show');
    });
