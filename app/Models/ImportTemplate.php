<?php

namespace App\Models;

use App\Enums\DedupeStrategy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'institution_id',
        'name',
        'column_mapping',
        'date_format',
        'flip_amount_sign',
        'dedupe_strategy',
        'dedupe_columns',
        'header_signature',
        'is_seeded',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'header_signature' => 'array',
            'flip_amount_sign' => 'boolean',
            'dedupe_strategy' => DedupeStrategy::class,
            'dedupe_columns' => 'array',
            'is_seeded' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
