<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Telecallers open and edit any patient: the Patients list, the profile's
 * clinical details, journey stage and contact details (LeadPolicy::update).
 * The Call Quality Analyst role mirrors the Telecaller role, so it receives
 * the same grant.
 */
return new class extends Migration
{
    private const ROLES = ['Telecaller', 'Call Quality Analyst'];

    private const PERMISSIONS = ['patients.view', 'patients.edit'];

    public function up(): void
    {
        // A fresh install runs migrations before the seeder; the seeder then
        // grants this itself.
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Role::whereIn('name', self::ROLES)->with('permissions')->get() as $role) {
            $role->givePermissionTo(self::PERMISSIONS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (Role::whereIn('name', self::ROLES)->with('permissions')->get() as $role) {
            foreach (self::PERMISSIONS as $name) {
                $role->revokePermissionTo($name);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
