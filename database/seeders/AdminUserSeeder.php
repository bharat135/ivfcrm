<?php

namespace Database\Seeders;

use App\Models\Center;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $centerId = Center::where('code', 'IVF-MAIN')->value('id');

        $admin = User::firstOrCreate(
            ['email' => 'admin@ivfcrm.com'],
            [
                'name'            => 'Super Admin',
                'employee_code'   => 'EMP001',
                'phone'           => '+91-9000000000',
                'password'        => Hash::make('Admin@123'),
                'center_id'       => $centerId,
                'designation'     => 'System Administrator',
                'is_active'       => true,
                'is_available'    => true,
            ]
        );
        $admin->assignRole('Super Admin');

        // Demo Telecaller
        $telecaller = User::firstOrCreate(
            ['email' => 'telecaller@ivfcrm.com'],
            [
                'name'          => 'Demo Telecaller',
                'employee_code' => 'EMP002',
                'phone'         => '+91-9000000003',
                'password'      => Hash::make('Demo@123'),
                'center_id'     => $centerId,
                'designation'   => 'Telecaller',
                'is_active'     => true,
                'is_available'  => true,
                'daily_lead_cap'=> 50,
            ]
        );
        $telecaller->assignRole('Telecaller');

        // Demo Center Manager
        $manager = User::firstOrCreate(
            ['email' => 'manager@ivfcrm.com'],
            [
                'name'          => 'Demo Manager',
                'employee_code' => 'EMP003',
                'phone'         => '+91-9000000004',
                'password'      => Hash::make('Demo@123'),
                'center_id'     => $centerId,
                'designation'   => 'Center Manager',
                'is_active'     => true,
                'is_available'  => true,
            ]
        );
        $manager->assignRole('Center Manager');
    }
}
