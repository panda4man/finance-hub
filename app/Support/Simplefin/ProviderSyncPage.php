<?php

declare(strict_types=1);

namespace App\Support\Simplefin;

final readonly class ProviderSyncPage
{
    /**
     * @param  list<string>  $errors  Non-fatal provider-side errors carried alongside partial data.
     * @param  list<ProviderAccount>  $accounts
     */
    public function __construct(
        public array $errors,
        public array $accounts,
    ) {}
}
