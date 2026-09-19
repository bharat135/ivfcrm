<?php

namespace Database\Seeders;

use App\Models\Center;
use App\Models\City;
use App\Models\State;
use Illuminate\Database\Seeder;

/**
 * The clinic's four centres in Maharashtra. Re-runnable: rows are matched by
 * code and updated in place, so an address correction here reaches every
 * environment on the next deploy.
 */
class CenterSeeder extends Seeder
{
    /**
     * @return array<int, array{name:string, code:string, city:string, address:string, pincode:?string}>
     */
    public static function definitions(): array
    {
        return [
            ['name' => 'Ulwe',   'code' => 'ULWE',   'city' => 'Navi Mumbai', 'address' => 'Sector 19, Ulwe, Navi Mumbai, Maharashtra',      'pincode' => '410206'],
            ['name' => 'Akola',  'code' => 'AKOLA',  'city' => 'Akola',       'address' => 'Near Main City Road, Akola, Maharashtra',        'pincode' => '444001'],
            ['name' => 'Shirur', 'code' => 'SHIRUR', 'city' => 'Shirur',      'address' => 'Main Market Road, Shirur, Maharashtra',          'pincode' => '412210'],
            ['name' => 'Thane',  'code' => 'THANE',  'city' => 'Thane',       'address' => 'Main Market Road, Thane, Maharashtra',           'pincode' => '400601'],
        ];
    }

    /** Codes of the placeholder centres the app shipped with before the real ones existed. */
    public const PLACEHOLDER_CODES = ['IVF-MAIN', 'IVF-BR1'];

    public function run(): void
    {
        $state = State::firstOrCreate(
            ['name' => 'Maharashtra'],
            ['code' => 'MH', 'country' => 'India', 'is_active' => true],
        );

        $ids = [];

        foreach (self::definitions() as $item) {
            $city = City::firstOrCreate(
                ['state_id' => $state->id, 'name' => $item['city']],
                ['is_active' => true],
            );

            $center = Center::withTrashed()->updateOrCreate(
                ['code' => $item['code']],
                [
                    'name'       => $item['name'],
                    'address'    => $item['address'],
                    'city_id'    => $city->id,
                    'state_id'   => $state->id,
                    'pincode'    => $item['pincode'],
                    'timezone'   => 'Asia/Kolkata',
                    'is_active'  => true,
                    'deleted_at' => null,
                ],
            );

            $ids[] = $center->id;
        }

        $this->retirePlaceholders($ids[0]);
    }

    /**
     * Everything that pointed at the shipped placeholder centres moves to the
     * first real centre, then the placeholders are switched off. Nothing is
     * deleted, so a reference from an old record can never dangle.
     */
    private function retirePlaceholders(int $fallbackCenterId): void
    {
        $placeholders = Center::whereIn('code', self::PLACEHOLDER_CODES)->pluck('id')->all();

        if (! $placeholders) {
            return;
        }

        $tables = [
            'users', 'leads', 'patients', 'appointments', 'doctors', 'campaigns', 'meta_lead_forms',
            'channel_accounts', 'lead_imports', 'landing_pages', 'teams', 'sip_extensions',
        ];

        foreach ($tables as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\Schema::hasColumn($table, 'center_id')) {
                \Illuminate\Support\Facades\DB::table($table)
                    ->whereIn('center_id', $placeholders)
                    ->update(['center_id' => $fallbackCenterId]);
            }
        }

        Center::whereIn('id', $placeholders)->update(['is_active' => false]);
    }
}
