<?php

declare(strict_types=1);

namespace App\Actions\ApiTokens;

use App\Models\User;
use Carbon\CarbonInterface;
use Laravel\Sanctum\NewAccessToken;

final class IssueApiTokenAction
{
    /**
     * @param  array<int, string>  $abilities  Ability names, matching TransactionPolicy method
     *                                         names (e.g. 'viewAny', 'view') — checked both as
     *                                         a Sanctum token ability (routes/api.php) and,
     *                                         via the underlying Policy, as the owner's own
     *                                         Spatie permission (Gate::authorize in the API
     *                                         controller).
     */
    public function execute(User $user, string $name, array $abilities, ?CarbonInterface $expiresAt): NewAccessToken
    {
        return $user->createToken($name, $abilities, $expiresAt);
    }
}
