<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Finance Insights')]
#[Version('0.1.0')]
class FinanceInsightsServer extends Server
{
    /**
     * @var array<string, array<string, bool>>
     */
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => ['listChanged' => false],
    ];

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        //
    ];

    protected string $instructions = <<<'MARKDOWN'
        Read-only access to the authenticated user's personal financial transactions.
        - Amounts: positive amounts are outflows (spending); negative amounts are inflows (income, refunds, credits).
        - Amount filters (amount_min/amount_max) compare magnitudes, so use `direction` to choose spending vs income.
        - Dates are ISO 8601 (YYYY-MM-DD) and inclusive.
        - Hidden and pending transactions are excluded unless include_hidden / include_pending is true.
        - Use list_accounts and search_merchants to resolve names before filtering by id.
        MARKDOWN;
}
