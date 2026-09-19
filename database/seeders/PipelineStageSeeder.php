<?php

namespace Database\Seeders;

use App\Models\PipelineStage;
use Illuminate\Database\Seeder;

class PipelineStageSeeder extends Seeder
{
    public function run(): void
    {
        $stages = [
            ['name' => 'New Lead',            'slug' => 'new-lead',           'color' => '#6c757d', 'sort_order' => 1],
            ['name' => 'Contacted',           'slug' => 'contacted',          'color' => '#0d6efd', 'sort_order' => 2],
            ['name' => 'Interested',          'slug' => 'interested',         'color' => '#0dcaf0', 'sort_order' => 3],
            ['name' => 'Consultation Booked', 'slug' => 'consultation-booked','color' => '#ffc107', 'sort_order' => 4],
            ['name' => 'Consultation Visited','slug' => 'consultation-visited','color' => '#fd7e14', 'sort_order' => 5],
            ['name' => 'Investigation',       'slug' => 'investigation',      'color' => '#6f42c1', 'sort_order' => 6],
            ['name' => 'Treatment Planning',  'slug' => 'treatment-planning', 'color' => '#d63384', 'sort_order' => 7],
            ['name' => 'IVF Started',         'slug' => 'ivf-started',        'color' => '#198754', 'sort_order' => 8],
            ['name' => 'Embryo Transfer',     'slug' => 'embryo-transfer',    'color' => '#20c997', 'sort_order' => 9],
            ['name' => 'Pregnancy Positive',  'slug' => 'pregnancy-positive', 'color' => '#198754', 'sort_order' => 10, 'is_won' => true],
            ['name' => 'Delivery',            'slug' => 'delivery',           'color' => '#28a745', 'sort_order' => 11, 'is_won' => true],
            ['name' => 'Lost',                'slug' => 'lost',               'color' => '#dc3545', 'sort_order' => 12, 'is_lost' => true],
        ];

        foreach ($stages as $stage) {
            PipelineStage::firstOrCreate(
                ['slug' => $stage['slug']],
                array_merge(['is_won' => false, 'is_lost' => false, 'is_active' => true], $stage)
            );
        }
    }
}
