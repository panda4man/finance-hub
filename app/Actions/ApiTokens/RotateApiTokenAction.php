<?php

declare(strict_types=1);

namespace App\Actions\ApiTokens;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

final class RotateApiTokenAction
{
    public function execute(PersonalAccessToken $old): NewAccessToken
    {
        return DB::transaction(function () use ($old): NewAccessToken {
            $expiresAt = $old->expires_at
                ? now()->addSeconds($old->created_at->diffInSeconds($old->expires_at))
                : null;

            $new = $old->tokenable->createToken($old->name, $old->abilities ?? ['*'], $expiresAt);

            $old->delete();

            return $new;
        });
    }
}
