<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Connection extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'provider',
        'credential_encrypted',
        'sync_cursor',
        'status',
        'status_detail',
        'consent_expiration_time',
        'last_successful_sync_at',
        'last_attempted_sync_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'credential_encrypted' => 'encrypted',
            'consent_expiration_time' => 'datetime',
            'last_successful_sync_at' => 'datetime',
            'last_attempted_sync_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<SyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
