<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A Call Quality Analyst audits the whole telecalling operation, so the role
 * sees every appointment (Appointment::scopeVisibleTo), not only the ones it
 * booked itself. appointments.view_own is dropped since view_all supersedes it.
 */
return new class extends Migration
{
    private const ROLE = 'Call Quality Analyst';

    public function up(): void
    {
        // A fresh install runs migrations before the seeder; the seeder then
        // grants this itself.
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'appointments.view_all', 'guard_name' => 'web']);

        $role = Role::where('name', self::ROLE)->with('permissions')->first();
        if ($role) {
            $role->givePermissionTo('appointments.view_all');
            $role->revokePermissionTo('appointments.view_own');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $role = Role::where('name', self::ROLE)->with('permissions')->first();
        if ($role) {
            $role->revokePermissionTo('appointments.view_all');
            $role->givePermissionTo('appointments.view_own');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
