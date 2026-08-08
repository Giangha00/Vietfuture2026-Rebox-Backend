<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Station;
use Illuminate\Auth\Access\HandlesAuthorization;

class StationPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Station');
    }

    public function view(AuthUser $authUser, Station $station): bool
    {
        return $authUser->can('View:Station');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Station');
    }

    public function update(AuthUser $authUser, Station $station): bool
    {
        return $authUser->can('Update:Station');
    }

    public function delete(AuthUser $authUser, Station $station): bool
    {
        return $authUser->can('Delete:Station');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Station');
    }

    public function restore(AuthUser $authUser, Station $station): bool
    {
        return $authUser->can('Restore:Station');
    }

    public function forceDelete(AuthUser $authUser, Station $station): bool
    {
        return $authUser->can('ForceDelete:Station');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Station');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Station');
    }

    public function replicate(AuthUser $authUser, Station $station): bool
    {
        return $authUser->can('Replicate:Station');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Station');
    }

}