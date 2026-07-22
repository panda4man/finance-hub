<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionCounterparty extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'name',
        'type',
        'entity_id',
        'website',
        'logo_url',
        'confidence_level',
    ];

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
