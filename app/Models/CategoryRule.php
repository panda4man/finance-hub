<?php

namespace App\Models;

use App\Enums\AmountSign;
use App\Enums\MatchField;
use App\Enums\MatchType;
use App\Enums\RuleSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'pattern',
        'match_field',
        'match_type',
        'amount_sign',
        'category_id',
        'priority',
        'source',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_field' => MatchField::class,
            'match_type' => MatchType::class,
            'amount_sign' => AmountSign::class,
            'source' => RuleSource::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
