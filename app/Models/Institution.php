<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider',
        'external_org_id',
        'name',
        'url',
        'primary_color',
        'logo_base64',
    ];

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
