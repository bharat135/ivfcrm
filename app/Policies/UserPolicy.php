<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->hasPermissionTo('users.view');
    }

    public function view(User $auth, User $user): bool
    {
        return $auth->hasPermissionTo('users.view');
    }

    public function create(User $auth): bool
    {
        return $auth->hasPermissionTo('users.create');
    }

    public function update(User $auth, User $user): bool
    {
        return $auth->hasPermissionTo('users.edit');
    }

    public function delete(User $auth, User $user): bool
    {
        return $auth->hasPermissionTo('users.delete') && $auth->id !== $user->id;
    }
}
