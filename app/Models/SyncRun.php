<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'connection_id',
        'trigger',
        'status',
        'started_at',
        'finished_at',
        'cursor_before',
        'cursor_after',
        'pages_fetched',
        'added_count',
        'modified_count',
        'removed_count',
        'accounts_upserted',
        'error_code',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => SyncTrigger::class,
            'status' => SyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pages_fetched' => 'integer',
            'added_count' => 'integer',
            'modified_count' => 'integer',
            'removed_count' => 'integer',
            'accounts_upserted' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
