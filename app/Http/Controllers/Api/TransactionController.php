<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IndexTransactionsRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TransactionController extends Controller
{
    public function index(IndexTransactionsRequest $request): AnonymousResourceCollection
    {
        // Token abilities (routes/api.php) scope what this key can do; this
        // scopes what the owning user account can do, via the same
        // TransactionPolicy Filament's own UI already enforces.
        Gate::authorize('viewAny', Transaction::class);

        $query = $this->ownedQuery($request);

        if ($dateFrom = $request->validated('date_from')) {
            $query->whereDate('transactions.date', '>=', $dateFrom);
        }

        if ($dateTo = $request->validated('date_to')) {
            $query->whereDate('transactions.date', '<=', $dateTo);
        }

        if ($accountId = $request->validated('account_id')) {
            $query->where('transactions.account_id', $accountId);
        }

        $perPage = $request->validated('per_page') ?? 50;

        $transactions = $query
            ->orderByDesc('transactions.date')
            ->orderByDesc('transactions.id')
            ->paginate($perPage)
            ->withQueryString();

        return TransactionResource::collection($transactions);
    }

    public function show(Request $request, string $transaction): TransactionResource
    {
        $model = $this->ownedQuery($request)->findOrFail($transaction);

        Gate::authorize('view', $model);

        return TransactionResource::make($model);
    }

    private function ownedQuery(Request $request): Builder
    {
        return Transaction::query()
            ->withEffectiveCategory()
            ->whereHas('connection', fn (Builder $q) => $q->where('user_id', $request->user()->getAuthIdentifier()))
            ->whereNull('transactions.removed_at')
            ->with([
                'account:id,name,account_type,institution_id',
                'account.institution:id,name',
                'category:id,name,slug',
                'userCategory:id,name,slug',
            ]);
    }
}
