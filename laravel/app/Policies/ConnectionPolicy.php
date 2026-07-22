<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Connection;
use Illuminate\Auth\Access\HandlesAuthorization;

class ConnectionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Connection');
    }

    public function view(AuthUser $authUser, Connection $connection): bool
    {
        return $authUser->can('View:Connection');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Connection');
    }

    public function update(AuthUser $authUser, Connection $connection): bool
    {
        return $authUser->can('Update:Connection');
    }

    public function delete(AuthUser $authUser, Connection $connection): bool
    {
        return $authUser->can('Delete:Connection');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Connection');
    }

    public function restore(AuthUser $authUser, Connection $connection): bool
    {
        return $authUser->can('Restore:Connection');
    }

    public function forceDelete(AuthUser $authUser, Connection $connection): bool
    {
        return $authUser->can('ForceDelete:Connection');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Connection');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Connection');
    }

    public function replicate(AuthUser $authUser, Connection $connection): bool
    {
        return $authUser->can('Replicate:Connection');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Connection');
    }

}