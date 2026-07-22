<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Transaction extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'connection_id',
        'external_transaction_id',
        'pending',
        'pending_external_transaction_id',
        'amount',
        'iso_currency_code',
        'unofficial_currency_code',
        'date',
        'authorized_date',
        'datetime',
        'authorized_datetime',
        'name',
        'merchant_name',
        'merchant_entity_id',
        'logo_url',
        'website',
        'payment_channel',
        'source_category_primary',
        'source_category_detailed',
        'source_category_confidence',
        'category_id',
        'user_category_id',
        'user_notes',
        'is_hidden',
        'location_city',
        'location_region',
        'location_country',
        'location_postal_code',
        'location_lat',
        'location_lon',
        'removed_at',
        'raw_payload',
        'first_seen_at',
        'last_modified_at',
    ];

    protected $hidden = [
        'search_tsv',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'location_lat' => 'decimal:6',
            'location_lon' => 'decimal:6',
            'pending' => 'boolean',
            'is_hidden' => 'boolean',
            'raw_payload' => 'array',
            'date' => 'date',
            'authorized_date' => 'date',
            'datetime' => 'datetime',
            'authorized_datetime' => 'datetime',
            'removed_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_modified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function userCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'user_category_id');
    }

    /**
     * @return HasMany<TransactionCounterparty, $this>
     */
    public function counterparties(): HasMany
    {
        return $this->hasMany(TransactionCounterparty::class);
    }

    public function scopeWithEffectiveCategory(Builder $query): Builder
    {
        return $query
            ->select('transactions.*')
            ->selectRaw('COALESCE(transactions.user_category_id, transactions.category_id) AS effective_category_id')
            ->selectRaw('eff_cat.slug AS effective_category_slug')
            ->selectRaw('eff_cat.name AS effective_category_name')
            ->leftJoin('categories as eff_cat', function ($join) {
                $join->on('eff_cat.id', '=', DB::raw('COALESCE(transactions.user_category_id, transactions.category_id)'));
            });
    }
}
