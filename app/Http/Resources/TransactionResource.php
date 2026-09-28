<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $effectiveCategory = $this->userCategory ?? $this->category;

        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'authorized_date' => $this->authorized_date?->toDateString(),
            'datetime' => $this->datetime?->toIso8601String(),
            'amount' => (string) $this->amount,
            'iso_currency_code' => $this->iso_currency_code,
            'name' => $this->name,
            'merchant_name' => $this->merchant_name,
            'pending' => $this->pending,
            'is_hidden' => $this->is_hidden,
            'payment_channel' => $this->payment_channel,
            'user_notes' => $this->user_notes,
            'account' => [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'type' => $this->account->account_type?->value,
                'institution' => $this->account->institution ? [
                    'id' => $this->account->institution->id,
                    'name' => $this->account->institution->name,
                ] : null,
            ],
            'category' => $effectiveCategory ? [
                'id' => $effectiveCategory->id,
                'slug' => $effectiveCategory->slug,
                'name' => $effectiveCategory->name,
            ] : null,
            'source_category' => [
                'primary' => $this->source_category_primary,
                'detailed' => $this->source_category_detailed,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
