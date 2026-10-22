<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $modules = [
            'leads', 'patients', 'appointments', 'followups', 'call_logs',
            'campaigns', 'lead_sources', 'doctors', 'centers', 'users',
            'roles', 'dispositions', 'pipeline_stages', 'assignment_rules',
            'lead_imports', 'reports', 'settings', 'counselling', 'treatments', 'leaves',
            // WhatsApp + Meta marketing suite
            'conversations', 'whatsapp_templates', 'whatsapp_flows', 'broadcasts', 'landing_pages', 'integrations',
            // Telephony (Asterisk / MicroSIP / WhatsApp calling)
            'telephony',
            // Tata Teleservices (Smartflo) configuration + sync + broadcasts
            'tata',
        ];

        $actions = ['view', 'create', 'edit', 'delete', 'export', 'approve'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['name' => "{$module}.{$action}"]);
            }
        }

        // Extra cross-cutting permissions
        // view_mobile_numbers: every role except Telecaller sees full numbers.
        foreach (['reassign_leads', 'view_all_leads', 'view_own_leads', 'bulk_actions', 'access_api', 'view_mobile_numbers'] as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        // Appointment scope (all / own centre / booked by me) and closing an appointment.
        foreach (['view_all', 'view_center', 'view_own', 'complete', 'cancel'] as $action) {
            Permission::firstOrCreate(['name' => "appointments.{$action}"]);
        }

        $roleDefinitions = [
            'Super Admin' => ['*'], // assigned programmatically
            'Admin' => [
                'leads.*', 'patients.*', 'appointments.*', 'followups.*', 'call_logs.*',
                'campaigns.*', 'lead_sources.*', 'doctors.*', 'centers.*', 'users.*',
                'roles.*', 'dispositions.*', 'pipeline_stages.*', 'assignment_rules.*',
                'lead_imports.*', 'reports.*', 'settings.*', 'counselling.*', 'treatments.*',
                'conversations.*', 'whatsapp_templates.*', 'whatsapp_flows.*', 'broadcasts.*', 'landing_pages.*',
                'integrations.*', 'telephony.*', 'tata.*',
                'view_all_leads', 'reassign_leads', 'bulk_actions', 'access_api',
            ],
            'Management' => [
                'leaves.*', 'view_mobile_numbers',
                'leads.*', 'patients.*', 'appointments.*', 'followups.*', 'call_logs.*',
                'campaigns.*', 'lead_sources.*', 'doctors.*', 'centers.*', 'users.*',
                'roles.*', 'dispositions.*', 'pipeline_stages.*', 'assignment_rules.*',
                'reports.*', 'counselling.*', 'treatments.*',
                'conversations.*', 'whatsapp_templates.*', 'whatsapp_flows.*', 'broadcasts.*', 'landing_pages.*',
                'integrations.view', 'telephony.*', 'tata.view', 'tata.edit',
                'view_all_leads', 'reassign_leads', 'bulk_actions',
            ],
            'Marketing Head' => [
                'leaves.view', 'view_mobile_numbers',
                'leads.view', 'leads.create', 'leads.edit', 'leads.export', 'assignment_rules.*',
                'campaigns.*', 'lead_sources.*', 'reports.view', 'reports.export',
                'conversations.*', 'whatsapp_templates.*', 'whatsapp_flows.*', 'broadcasts.*', 'landing_pages.*',
                'integrations.view', 'integrations.edit',
                'view_all_leads', 'bulk_actions',
            ],
            'Marketing Executive' => [
                'leads.view', 'leads.create', 'leads.edit', 'leads.export',
                'campaigns.view', 'campaigns.create', 'campaigns.edit',
                'lead_sources.view', 'reports.view',
                'conversations.view', 'conversations.create', 'conversations.edit',
                'whatsapp_templates.view', 'whatsapp_templates.create',
                'whatsapp_flows.view', 'whatsapp_flows.create',
                'broadcasts.view', 'broadcasts.create',
                'landing_pages.*',
                'view_all_leads',
            ],
            'Center Manager' => [
                'leaves.*', 'view_mobile_numbers',
                'leads.*', 'patients.*', 'followups.*', 'call_logs.*',
                'appointments.view', 'appointments.create', 'appointments.edit', 'appointments.delete',
                'appointments.export', 'appointments.approve',
                'appointments.view_center', 'appointments.complete', 'appointments.cancel',
                'doctors.view', 'reports.view', 'reports.export', 'counselling.*',
                'conversations.*', 'whatsapp_templates.view', 'whatsapp_flows.view', 'whatsapp_flows.create',
                'broadcasts.view', 'broadcasts.create', 'telephony.view', 'telephony.edit',
                'tata.view', 'tata.edit', 'tata.create',
                'view_all_leads', 'reassign_leads',
            ],
            'Doctor' => [
                'patients.view', 'patients.edit', 'appointments.view', 'appointments.edit',
                'appointments.view_center', 'appointments.complete', 'appointments.cancel',
                'treatments.*', 'counselling.view',
            ],
            'IVF Coordinator' => [
                'patients.*', 'appointments.*', 'treatments.*', 'counselling.*',
                'leads.view', 'followups.view',
            ],
            'Counsellor' => [
                'leaves.view', 'view_mobile_numbers',
                'leads.view', 'leads.create', 'leads.edit', 'patients.view', 'patients.edit',
                'appointments.view', 'appointments.create', 'appointments.edit', 'appointments.delete',
                'appointments.export', 'appointments.approve', 'appointments.view_center',
                'counselling.*', 'followups.*',
                'conversations.view', 'conversations.create', 'conversations.edit',
                'whatsapp_templates.view',
            ],
            'Telecaller' => [
                'leaves.view', // no view_mobile_numbers: numbers are masked for telecallers
                'leads.view', 'leads.create', 'leads.edit', 'patients.view', 'patients.edit', 'followups.*', 'call_logs.*',
                'appointments.view', 'appointments.create', 'appointments.view_own',
                'conversations.view', 'conversations.create', 'conversations.edit',
                'whatsapp_templates.view', 'whatsapp_flows.view', 'whatsapp_flows.create',
                'view_own_leads',
            ],
            // Everything a Telecaller has, plus the audit extras of the role.
            'Call Quality Analyst' => [
                'leaves.view',
                'leads.view', 'leads.create', 'leads.edit', 'patients.view', 'patients.edit', 'followups.*', 'call_logs.*',
                'appointments.view', 'appointments.create', 'appointments.view_own',
                'conversations.view', 'conversations.create', 'conversations.edit',
                'whatsapp_templates.view', 'whatsapp_flows.view', 'whatsapp_flows.create',
                'view_own_leads',
                'reports.view', 'telephony.view',
            ],
            'Receptionist' => [
                'leaves.view', 'view_mobile_numbers',
                'appointments.view', 'appointments.create', 'appointments.export',
                'appointments.view_center', 'appointments.cancel',
                'patients.view', 'patients.create',
                'leads.view', 'leads.create',
                'conversations.view', 'conversations.create',
            ],
            'Finance' => [
                'reports.*', 'patients.view', 'treatments.view',
            ],
            'Patient Support' => [
                'patients.view', 'patients.edit', 'followups.*', 'appointments.view', 'appointments.view_center',
                'conversations.view', 'conversations.create', 'conversations.edit',
                'whatsapp_templates.view',
            ],
            'Viewer' => [
                'leads.view', 'patients.view', 'reports.view',
            ],
        ];

        $allPerms = Permission::pluck('name')->toArray();

        foreach ($roleDefinitions as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName]);

            if ($perms === ['*']) {
                $role->syncPermissions($allPerms);
                continue;
            }

            $toAssign = [];
            foreach ($perms as $perm) {
                if (str_ends_with($perm, '.*')) {
                    $module = str_replace('.*', '', $perm);
                    foreach ($allPerms as $p) {
                        if (str_starts_with($p, $module . '.')) {
                            $toAssign[] = $p;
                        }
                    }
                } else {
                    $toAssign[] = $perm;
                }
            }
            $role->syncPermissions(array_filter(array_unique($toAssign), fn($p) => in_array($p, $allPerms)));
        }
    }
}
