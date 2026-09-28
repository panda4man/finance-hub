<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IndexTransactionsRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\TransactionQueryService;
use App\Support\Transactions\TransactionFilters;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionQueryService $transactions) {}

    public function index(IndexTransactionsRequest $request): AnonymousResourceCollection
    {
        // Token abilities (routes/api.php) scope what this key can do; this
        // scopes what the owning user account can do, via the same
        // TransactionPolicy Filament's own UI already enforces.
        Gate::authorize('viewAny', Transaction::class);

        $filters = TransactionFilters::fromArray([
            'date_from' => $request->validated('date_from'),
            'date_to' => $request->validated('date_to'),
            'account_ids' => ($accountId = $request->validated('account_id')) ? [$accountId] : [],
            // The REST API has always returned hidden/pending rows; keep that
            // behavior even though the shared query service now defaults to
            // excluding them for the MCP tools.
            'include_pending' => true,
            'include_hidden' => true,
        ]);

        $query = $this->transactions->applyFilters($this->transactions->ownedQuery($request->user()), $filters);

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
        $model = $this->transactions->ownedQuery($request->user())->findOrFail($transaction);

        Gate::authorize('view', $model);

        return TransactionResource::make($model);
    }
}
