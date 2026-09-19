<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Front-line staff enter walk-in and phone enquiries themselves, so
 * Telecallers and Counsellors need `leads.create`; until now only the seeder
 * defined role permissions, so a live database never received the change.
 *
 * Management and the Marketing Head also get the (already existing)
 * `assignment_rules.*` permissions for the new Lead Assignment settings page.
 */
return new class extends Migration
{
    private const GRANTS = [
        'Telecaller'     => ['leads.create'],
        'Counsellor'     => ['leads.create'],
        'Management'     => ['assignment_rules.view', 'assignment_rules.create', 'assignment_rules.edit', 'assignment_rules.delete', 'assignment_rules.export', 'assignment_rules.approve'],
        'Marketing Head' => ['assignment_rules.view', 'assignment_rules.create', 'assignment_rules.edit', 'assignment_rules.delete', 'assignment_rules.export', 'assignment_rules.approve'],
    ];

    public function up(): void
    {
        // A fresh install runs migrations before the seeder; the seeder then
        // grants everything below itself.
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANTS as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            foreach ($permissions as $permission) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => $role->guard_name]);
            }

            $role->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Telecaller', 'Counsellor'] as $roleName) {
            Role::where('name', $roleName)->first()?->revokePermissionTo('leads.create');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
