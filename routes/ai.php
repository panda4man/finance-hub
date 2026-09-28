<?php

use App\Mcp\Servers\FinanceInsightsServer;
use App\Models\Transaction;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/finance', FinanceInsightsServer::class)
    ->middleware(['throttle:api', 'auth:sanctum', 'abilities:viewAny', 'can:viewAny,'.Transaction::class])
    ->name('mcp.finance');
