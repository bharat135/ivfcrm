<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holiday calendar + leave cover, and the "who may see mobile numbers"
 * permission (telecallers do not).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Spatie loads role permissions lazily; the app forbids lazy loads.
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(false);

        if (! Schema::hasTable('agent_leaves')) Schema::create('agent_leaves', function (Blueprint $table) {
            $table->id();
            // null = clinic-wide holiday (everyone is off)
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('type', 20)->default('leave')->comment('leave, holiday');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'starts_on', 'ends_on']);
            $table->index(['starts_on', 'ends_on']);
        });

        if (! Schema::hasColumn('follow_ups', 'delegated_to')) Schema::table('follow_ups', function (Blueprint $table) {
            // Cover while the owner is on leave; the lead's owner is untouched.
            $table->foreignId('delegated_to')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('delegated_reason')->nullable()->after('delegated_to');
            $table->index(['delegated_to', 'status', 'scheduled_at']);
        });

        if (! Schema::hasTable('roles')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = Role::query()->value('guard_name') ?: 'web';

        Permission::firstOrCreate(['name' => 'view_mobile_numbers', 'guard_name' => $guard]);
        foreach (['view', 'create', 'edit', 'delete', 'export', 'approve'] as $action) {
            Permission::firstOrCreate(['name' => "leaves.{$action}", 'guard_name' => $guard]);
        }

        foreach (Role::all() as $role) {
            if ($role->name !== 'Telecaller') {
                $role->givePermissionTo('view_mobile_numbers');
            }

            if (in_array($role->name, ['Super Admin', 'Admin', 'Management', 'Center Manager'], true)) {
                $role->givePermissionTo(['leaves.view', 'leaves.create', 'leaves.edit', 'leaves.delete']);
            } elseif (in_array($role->name, ['Telecaller', 'Counsellor', 'Receptionist', 'Marketing Head'], true)) {
                $role->givePermissionTo('leaves.view');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delegated_to');
            $table->dropColumn('delegated_reason');
        });
        Schema::dropIfExists('agent_leaves');
    }
};
