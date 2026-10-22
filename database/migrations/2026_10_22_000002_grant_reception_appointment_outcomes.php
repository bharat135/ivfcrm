<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reception closes the loop on a visit: mark the patient as visited, cancel
 * with a reason, or reschedule. The earlier grant left them with cancel only.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['appointments.edit', 'appointments.complete', 'appointments.cancel'];

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        if ($role = Role::where('name', 'Receptionist')->with('permissions')->first()) {
            $role->givePermissionTo(self::PERMISSIONS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if ($role = Role::where('name', 'Receptionist')->with('permissions')->first()) {
            $role->revokePermissionTo(['appointments.edit', 'appointments.complete']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
