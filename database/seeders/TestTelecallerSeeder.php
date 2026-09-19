<?php

namespace Database\Seeders;

use App\Models\Center;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One throwaway telecaller for exercising click-to-call end to end.
 *
 * Not part of DatabaseSeeder on purpose — run it explicitly:
 *
 *   php artisan db:seed --class=TestTelecallerSeeder
 *
 * Set TEST_TELECALLER_MOBILE (or edit the user afterwards under Settings →
 * Users) to the mobile registered as an agent in Tata Smartflo, so the cloud
 * PBX has a number to ring for the first leg of the call.
 */
class TestTelecallerSeeder extends Seeder
{
    public const EMAIL    = 'test.telecaller@ivfcrm.com';
    public const PASSWORD = 'Test@1234';

    public function run(): void
    {
        $centerId = Center::where('code', 'IVF-MAIN')->value('id') ?? Center::value('id');
        $mobile   = env('TEST_TELECALLER_MOBILE');

        $user = User::withTrashed()->firstOrNew(['email' => self::EMAIL]);

        $user->fill([
            'name'              => 'Test Telecaller',
            'employee_code'     => $user->employee_code ?: 'EMP-TEST01',
            'phone'             => $mobile ?: ($user->phone ?: '+91-9000000099'),
            'center_id'         => $user->center_id ?: $centerId,
            'designation'       => 'Telecaller (Test)',
            'language'          => 'en',
            'is_active'         => true,
            'is_available'      => true,
            'daily_lead_cap'    => 20,
            'call_status'       => 'idle',
            'tata_agent_number' => $mobile ?: $user->tata_agent_number,
        ]);

        // Always reset the password so the documented login keeps working.
        $user->password = Hash::make(self::PASSWORD);

        if ($user->trashed()) {
            $user->restore();
        }

        $user->save();
        $user->syncRoles(['Telecaller']);

        $this->command?->info(sprintf(
            'Test telecaller ready: %s / %s (Tata agent number: %s)',
            self::EMAIL,
            self::PASSWORD,
            $user->tata_agent_number ?: 'not set — falls back to phone ' . $user->phone,
        ));
    }
}
