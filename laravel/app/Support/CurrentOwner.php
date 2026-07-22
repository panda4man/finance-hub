<?php

namespace App\Support;

use Filament\Facades\Filament;

/**
 * Centralizes "who owns this record" resolution for Filament resources.
 *
 * There is no real multi-tenancy relationship yet (see AdminPanelProvider's
 * commented-out ->tenant(User::class)), so every resource manually scopes its
 * Eloquent query to the logged-in user via this helper. When tenancy is
 * activated later, delete the manual getEloquentQuery() ownership clauses in
 * each resource in favor of Filament's tenant-aware scoping — this class can
 * then be removed entirely.
 */
class CurrentOwner
{
    public static function id(): ?string
    {
        return Filament::auth()->id();
    }
}
