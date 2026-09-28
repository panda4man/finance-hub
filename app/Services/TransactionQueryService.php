<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AmountSign;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Transactions\TransactionFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TransactionQueryService
{
    /**
     * Base, ownership-scoped query: the user's non-removed transactions with
     * effective-category columns and the account/institution/category relations
     * eager loaded. Applies no pending/hidden filtering — that's applyFilters()'s job.
     *
     * @return Builder<Transaction>
     */
    public function ownedQuery(User $user): Builder
    {
        return Transaction::query()
            ->withEffectiveCategory()
            ->whereHas('connection', fn (Builder $q) => $q->where('user_id', $user->getAuthIdentifier()))
            ->whereNull('transactions.removed_at')
            ->with([
                'account:id,name,account_type,institution_id',
                'account.institution:id,name',
                'category:id,name,slug',
                'userCategory:id,name,slug',
            ]);
    }

    /**
     * Applies $filters to $query in place and returns the same instance.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function applyFilters(Builder $query, TransactionFilters $filters): Builder
    {
        if ($filters->dateFrom !== null) {
            $query->whereDate('transactions.date', '>=', $filters->dateFrom->toDateString());
        }

        if ($filters->dateTo !== null) {
            $query->whereDate('transactions.date', '<=', $filters->dateTo->toDateString());
        }

        if ($filters->amountMin !== null) {
            $query->whereRaw('ABS(transactions.amount) >= CAST(? AS DECIMAL(14,2))', [$filters->amountMin]);
        }

        if ($filters->amountMax !== null) {
            $query->whereRaw('ABS(transactions.amount) <= CAST(? AS DECIMAL(14,2))', [$filters->amountMax]);
        }

        match ($filters->direction) {
            AmountSign::Outflow => $query->where('transactions.amount', '>', 0),
            AmountSign::Inflow => $query->where('transactions.amount', '<', 0),
            AmountSign::Any => null,
        };

        if ($filters->accountTypes !== []) {
            $query->whereHas('account', fn (Builder $a) => $a->whereIn(
                'account_type',
                array_map(fn ($type) => $type->value, $filters->accountTypes),
            ));
        }

        if ($filters->accountIds !== []) {
            $query->whereIn('transactions.account_id', $filters->accountIds);
        }

        if ($filters->institution !== null) {
            if (Str::isUuid($filters->institution)) {
                $query->whereHas('account', fn (Builder $a) => $a->where('institution_id', $filters->institution));
            } else {
                $query->whereHas(
                    'account.institution',
                    fn (Builder $i) => $i->whereLike('name', '%'.$this->escapeLike($filters->institution).'%'),
                );
            }
        }

        if ($filters->merchant !== null) {
            $pattern = '%'.$this->escapeLike($filters->merchant).'%';
            $query->where(
                fn (Builder $m) => $m
                    ->whereLike('transactions.merchant_name', $pattern)
                    ->orWhereLike('transactions.name', $pattern),
            );
        }

        if ($filters->categorySlugs !== []) {
            $query->whereIn(
                DB::raw('COALESCE(transactions.user_category_id, transactions.category_id)'),
                Category::query()->select('id')->whereIn('slug', $filters->categorySlugs),
            );
        }

        if (! $filters->includePending) {
            $query->where('transactions.pending', false);
        }

        if (! $filters->includeHidden) {
            $query->where('transactions.is_hidden', false);
        }

        return $query;
    }

    /**
     * Escapes LIKE/ILIKE wildcard characters (and the escape character itself)
     * so user-supplied merchant/institution text can't inject a pattern.
     * Relies on backslash being the default LIKE escape character (pgsql, MySQL).
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
