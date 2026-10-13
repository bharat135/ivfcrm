<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A quality analyst works leads like a telecaller when a custom assignment
 * rule (or a manager) gives them one, so the role receives whatever the
 * Telecaller role holds on this database, on top of its own audit
 * permissions. Only the seeder defined role permissions, so a live database
 * needs the grant spelled out here.
 */
return new class extends Migration
{
    private const ROLE = 'Call Quality Analyst';

    public function up(): void
    {
        // A fresh install runs migrations before the seeder; the seeder then
        // grants everything below itself.
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $analyst    = Role::where('name', self::ROLE)->first();
        $telecaller = Role::where('name', 'Telecaller')->first();

        if ($analyst && $telecaller) {
            $permissions = Permission::whereIn('id', $telecaller->permissions()->pluck('id'))
                ->where('guard_name', $analyst->guard_name)
                ->get();

            $analyst->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The grant copies whatever the Telecaller role held at the time, so
        // there is no fixed list to take back; adjust under Settings → Roles.
    }
};
