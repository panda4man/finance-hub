<?php

use App\Mcp\Servers\FinanceInsightsServer;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\FakeTransporter;

function mcpServerContext(): ServerContext
{
    return app(FinanceInsightsServer::class, ['transport' => new FakeTransporter])->createContext();
}

it('identifies itself as Finance Insights', function () {
    $ctx = mcpServerContext();

    expect($ctx->implementation->toArray()['name'])->toBe('Finance Insights');
    expect($ctx->implementation->toArray()['version'])->toBe('0.1.0');
});

it('advertises only the tools capability', function () {
    $ctx = mcpServerContext();

    expect(array_keys($ctx->serverCapabilities))->toBe(['tools']);
});

it('explains the amount sign convention and hidden/pending defaults in its instructions', function () {
    $ctx = mcpServerContext();

    expect($ctx->instructions)->toContain('positive amounts are outflows');
    expect($ctx->instructions)->toContain('include_hidden');
    expect($ctx->instructions)->toContain('include_pending');
});

it('registers the finance tools', function () {
    $ctx = mcpServerContext();

    expect($ctx->tools()->map->name()->values()->all())->toBe([
        'list_accounts',
        'search_merchants',
        'search_transactions',
        'find_repeat_purchases',
        'spending_trend',
    ]);
});
