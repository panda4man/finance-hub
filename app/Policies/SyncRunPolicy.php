<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\SyncRun;
use Illuminate\Auth\Access\HandlesAuthorization;

class SyncRunPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SyncRun');
    }

    public function view(AuthUser $authUser, SyncRun $syncRun): bool
    {
        return $authUser->can('View:SyncRun');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SyncRun');
    }

    public function update(AuthUser $authUser, SyncRun $syncRun): bool
    {
        return $authUser->can('Update:SyncRun');
    }

    public function delete(AuthUser $authUser, SyncRun $syncRun): bool
    {
        return $authUser->can('Delete:SyncRun');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SyncRun');
    }

    public function restore(AuthUser $authUser, SyncRun $syncRun): bool
    {
        return $authUser->can('Restore:SyncRun');
    }

    public function forceDelete(AuthUser $authUser, SyncRun $syncRun): bool
    {
        return $authUser->can('ForceDelete:SyncRun');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SyncRun');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SyncRun');
    }

    public function replicate(AuthUser $authUser, SyncRun $syncRun): bool
    {
        return $authUser->can('Replicate:SyncRun');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SyncRun');
    }

}