<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithTransactionFilters;
use App\Models\Account;
use App\Services\TransactionQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_accounts')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Description(<<<'MARKDOWN'
    Lists the authenticated user's accounts, grouped by institution, with balances and
    transaction counts. Call this first to resolve a bank name ("Chase") or account
    nickname to account ids / institution ids / account types before filtering other
    tools. Debit-card purchases post to "checking" accounts — there is no separate
    debit account type.
    MARKDOWN
)]
class ListAccounts extends Tool
{
    use InteractsWithTransactionFilters;

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->resolveUser($request);

        $validated = $request->validate(Arr::only(
            $this->transactionFilterRules(),
            ['institution', 'account_types', 'account_types.*'],
        ));

        $service = app(TransactionQueryService::class);

        $query = Account::query()
            ->whereHas('connection', fn ($q) => $q->where('user_id', $user->id))
            ->with('institution:id,name')
            ->withCount(['transactions as transaction_count' => fn ($q) => $q->whereNull('removed_at')])
            ->withMin(['transactions as first_transaction_date' => fn ($q) => $q->whereNull('removed_at')], 'date')
            ->withMax(['transactions as last_transaction_date' => fn ($q) => $q->whereNull('removed_at')], 'date');

        if ($institution = $validated['institution'] ?? null) {
            if (Str::isUuid($institution)) {
                $query->where('institution_id', $institution);
            } else {
                $query->whereHas(
                    'institution',
                    fn ($q) => $q->whereLike('name', '%'.$service->escapeLike($institution).'%'),
                );
            }
        }

        if ($accountTypes = $validated['account_types'] ?? []) {
            $query->whereIn('account_type', $accountTypes);
        }

        $accounts = $query->get()->sortBy([
            fn (Account $a, Account $b) => ($a->institution?->name ?? '') <=> ($b->institution?->name ?? ''),
            fn (Account $a, Account $b) => $a->name <=> $b->name,
        ])->values();

        $institutions = $accounts
            ->filter(fn (Account $a) => $a->institution !== null)
            ->groupBy('institution.id')
            ->map(fn ($group) => [
                'id' => $group->first()->institution->id,
                'name' => $group->first()->institution->name,
                'account_count' => $group->count(),
            ])
            ->values()
            ->all();

        return Response::structured([
            'applied_filters' => [
                'institution' => $validated['institution'] ?? null,
                'account_types' => $validated['account_types'] ?? [],
            ],
            'count' => $accounts->count(),
            'institutions' => $institutions,
            'accounts' => $accounts->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'display_name' => $a->display_name,
                'official_name' => $a->official_name,
                'mask' => $a->mask,
                'type' => $a->account_type?->value,
                'type_label' => $a->account_type?->label(),
                'institution' => $a->institution ? ['id' => $a->institution->id, 'name' => $a->institution->name] : null,
                'current_balance' => $a->current_balance === null ? null : $this->money($a->current_balance),
                'available_balance' => $a->available_balance === null ? null : $this->money($a->available_balance),
                'iso_currency_code' => $a->iso_currency_code,
                'transaction_count' => $a->transaction_count,
                'first_transaction_date' => $a->first_transaction_date,
                'last_transaction_date' => $a->last_transaction_date,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return Arr::only($this->transactionFilterSchema($schema), ['institution', 'account_types']);
    }
}
