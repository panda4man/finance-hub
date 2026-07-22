<?php

declare(strict_types=1);

namespace App\Support\Simplefin;

use Carbon\CarbonImmutable;

final readonly class ProviderTransaction
{
    /**
     * @param  array<string, mixed>  $rawPayload  Original, untouched provider payload for this transaction.
     */
    public function __construct(
        public string $externalTransactionId,
        public bool $pending,
        public string $amount,
        public CarbonImmutable $date,
        public ?CarbonImmutable $datetime,
        public string $name,
        public array $rawPayload,
    ) {}
}
