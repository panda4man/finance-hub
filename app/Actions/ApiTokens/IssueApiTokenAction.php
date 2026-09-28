<?php

declare(strict_types=1);

namespace App\Actions\ApiTokens;

use App\Models\User;
use Carbon\CarbonInterface;
use Laravel\Sanctum\NewAccessToken;

final class IssueApiTokenAction
{
    public function execute(User $user, string $name, ?CarbonInterface $expiresAt): NewAccessToken
    {
        return $user->createToken($name, ['transactions:read'], $expiresAt);
    }
}
