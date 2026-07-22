<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CategoryRule;
use Illuminate\Auth\Access\HandlesAuthorization;

class CategoryRulePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CategoryRule');
    }

    public function view(AuthUser $authUser, CategoryRule $categoryRule): bool
    {
        return $authUser->can('View:CategoryRule');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CategoryRule');
    }

    public function update(AuthUser $authUser, CategoryRule $categoryRule): bool
    {
        return $authUser->can('Update:CategoryRule');
    }

    public function delete(AuthUser $authUser, CategoryRule $categoryRule): bool
    {
        return $authUser->can('Delete:CategoryRule');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CategoryRule');
    }

    public function restore(AuthUser $authUser, CategoryRule $categoryRule): bool
    {
        return $authUser->can('Restore:CategoryRule');
    }

    public function forceDelete(AuthUser $authUser, CategoryRule $categoryRule): bool
    {
        return $authUser->can('ForceDelete:CategoryRule');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CategoryRule');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CategoryRule');
    }

    public function replicate(AuthUser $authUser, CategoryRule $categoryRule): bool
    {
        return $authUser->can('Replicate:CategoryRule');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CategoryRule');
    }

}