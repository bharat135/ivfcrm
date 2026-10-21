<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function create(User $user): bool
    {
        return $user->can('leads.create') || $user->can('lead_imports.create');
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($user->hasRole(['Super Admin', 'Management', 'Marketing Head', 'Call Quality Analyst'])) {
            return true;
        }
        if ($user->hasRole('Center Manager') && $lead->center_id === $user->center_id) {
            return true;
        }
        return $lead->assigned_to === $user->id;
    }

    public function update(User $user, Lead $lead): bool
    {
        if ($user->hasRole(['Super Admin', 'Management', 'Center Manager'])) {
            return true;
        }
        // Telecallers with patient editing work on any patient, not only their own
        if ($user->hasRole(['Telecaller', 'Call Quality Analyst'])
            && $user->can('leads.edit') && $user->can('patients.edit')) {
            return true;
        }
        return $lead->assigned_to === $user->id && $user->can('leads.edit');
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->hasRole(['Super Admin', 'Management']) && $user->can('leads.delete');
    }
}
