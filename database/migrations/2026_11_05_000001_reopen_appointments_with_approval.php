<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * An appointment marked done (visited) or no-show by mistake can be put back
 * to booked, but only with an admin's say-so:
 *
 *   reopen_appointments   reopen a closed appointment, and approve or decline
 *                         the requests other users send
 *
 * Everyone else who could have closed the appointment, or who booked it, asks
 * for the correction with a reason; the request waits on the appointment.
 * The permission sits outside appointments.* so that wildcard does not grant it.
 */
return new class extends Migration
{
    private const PERMISSION = 'reopen_appointments';

    private const ROLES = ['Admin', 'Management'];

    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->text('reopen_reason')->nullable()->after('cancelled_at');
            $table->foreignId('reopen_requested_by')->nullable()->after('reopen_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reopen_requested_at')->nullable()->after('reopen_requested_by');
        });

        // A fresh install runs migrations before the seeder; the seeder grants this itself.
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        foreach (Role::whereIn('name', self::ROLES)->with('permissions')->get() as $role) {
            $role->givePermissionTo(self::PERMISSION);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopen_requested_by');
            $table->dropColumn(['reopen_reason', 'reopen_requested_at']);
        });

        if (Schema::hasTable('permissions')) {
            Permission::where('name', self::PERMISSION)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
