<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who sees which appointments, and who may close them:
 *
 *   appointments.view_all     every appointment
 *   appointments.view_center  the appointments of the user's own centre
 *   appointments.view_own     only the appointments the user booked
 *   appointments.complete     mark visited / no-show
 *   appointments.cancel       cancel, with a reason
 *
 * A cancellation records its reason, who cancelled and when.
 */
return new class extends Migration
{
    private const GRANTS = [
        'appointments.view_all'    => ['Admin', 'Management', 'IVF Coordinator'],
        'appointments.view_center' => ['Center Manager', 'Receptionist', 'Doctor', 'Counsellor', 'Patient Support'],
        'appointments.view_own'    => ['Telecaller', 'Call Quality Analyst'],
        'appointments.complete'    => ['Admin', 'Management', 'Center Manager', 'IVF Coordinator', 'Doctor'],
        'appointments.cancel'      => ['Admin', 'Management', 'Center Manager', 'IVF Coordinator', 'Doctor', 'Receptionist'],
    ];

    // Reception only books and cancels; marking done is the doctor's.
    private const REVOKE = ['Receptionist' => ['appointments.edit', 'appointments.delete', 'appointments.approve']];

    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->text('cancel_reason')->nullable()->after('notes');
            $table->foreignId('cancelled_by')->nullable()->after('cancel_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
        });

        // A fresh install runs migrations before the seeder; the seeder grants these itself.
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANTS as $name => $roles) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            foreach (Role::whereIn('name', $roles)->with('permissions')->get() as $role) {
                $role->givePermissionTo($name);
            }
        }

        foreach (self::REVOKE as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->with('permissions')->first();
            foreach ($permissions as $name) {
                if ($role && $role->hasPermissionTo($name)) {
                    $role->revokePermissionTo($name);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancel_reason', 'cancelled_at']);
        });

        if (Schema::hasTable('permissions')) {
            Permission::whereIn('name', array_keys(self::GRANTS))->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
