<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CenterSeeder::class,
            LeadSourceSeeder::class,
            PipelineStageSeeder::class,
            DispositionSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
