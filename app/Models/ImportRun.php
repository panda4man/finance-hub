<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRun extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'connection_id',
        'account_id',
        'status',
        'file_name',
        'file_path',
        'row_count',
        'added_count',
        'duplicate_count',
        'failed_count',
        'started_at',
        'finished_at',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'row_count' => 'integer',
            'added_count' => 'integer',
            'duplicate_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
