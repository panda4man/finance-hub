<?php

declare(strict_types=1);

namespace App\Support\Simplefin;

use Carbon\CarbonImmutable;

final readonly class ProviderAccount
{
    /**
     * @param  list<ProviderTransaction>  $transactions
     */
    public function __construct(
        public string $externalAccountId,
        public string $name,
        public string $isoCurrencyCode,
        public ?string $currentBalance,
        public ?string $availableBalance,
        public ?CarbonImmutable $balancesUpdatedAt,
        public ProviderInstitution $institution,
        public array $transactions,
    ) {}
}
