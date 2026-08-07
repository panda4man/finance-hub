<?php

declare(strict_types=1);

namespace App\Support\Simplefin;

final readonly class ProviderSyncPage
{
    /**
     * @param  list<string>  $errors  Non-fatal provider-side errors carried alongside partial data.
     * @param  list<ProviderAccount>  $accounts  Only accounts NOT excluded by a scoped auth error in $authErrors.
     * @param  list<array{connId: ?string, institutionName: ?string, code: string, msg: string}>  $authErrors  Per-institution
     *                                                                                                         (con.auth) auth failures scoped to a single conn_id — that institution's accounts are already excluded
     *                                                                                                         from $accounts, other institutions under the same credential are unaffected.
     */
    public function __construct(
        public array $errors,
        public array $accounts,
        public array $authErrors = [],
    ) {}
}
