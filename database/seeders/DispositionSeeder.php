<?php

namespace Database\Seeders;

use App\Models\Disposition;
use App\Models\PipelineStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The clinic's call outcomes: one flat list, no sub-dispositions.
 *
 * Every telecaller pick lands in exactly one of these. Flags drive the
 * disposition engine — `requires_followup` forces a callback time,
 * `is_lost` closes the enquiry, `stage_slug` moves it along the pipeline.
 */
class DispositionSeeder extends Seeder
{
    /**
     * @return array<int, array{name:string, color:string, requires_followup?:bool, is_lost?:bool, stage_slug?:string}>
     */
    public static function definitions(): array
    {
        return [
            ['name' => 'Visit done',                'color' => '#198754', 'stage_slug' => 'consultation-visited'],
            ['name' => 'Already Visit done',        'color' => '#198754', 'stage_slug' => 'consultation-visited'],
            ['name' => 'Invalid Number',            'color' => '#dc3545', 'is_lost' => true],
            ['name' => 'Do Not disturb',            'color' => '#dc3545', 'is_lost' => true],
            ['name' => 'Fake Lead',                 'color' => '#dc3545', 'is_lost' => true],
            ['name' => 'Not interested',            'color' => '#dc3545', 'is_lost' => true],
            ['name' => 'Not Looking for Treatment', 'color' => '#dc3545', 'is_lost' => true],
            ['name' => 'Not inquired',              'color' => '#dc3545', 'is_lost' => true],
            ['name' => 'Cold Lead',                 'color' => '#6c757d'],
            ['name' => 'Location not preferred',    'color' => '#dc3545', 'is_lost' => true],
            ['name' => 'Warm Lead',                 'color' => '#ffc107', 'requires_followup' => true, 'stage_slug' => 'interested'],
            ['name' => 'Ringing',                   'color' => '#fd7e14', 'requires_followup' => true],
            ['name' => 'Interested to Visit',       'color' => '#0dcaf0', 'requires_followup' => true, 'stage_slug' => 'interested'],
            ['name' => 'Appointment Booked',        'color' => '#0d6efd', 'stage_slug' => 'consultation-booked'],
            ['name' => 'Call Disconnected',         'color' => '#fd7e14', 'requires_followup' => true],
            ['name' => 'Call back later',           'color' => '#ffc107', 'requires_followup' => true],
            ['name' => 'Wrong Number',              'color' => '#dc3545', 'is_lost' => true],
            ['name' => 'Switch Off',                'color' => '#fd7e14', 'requires_followup' => true],
            ['name' => 'Busy',                      'color' => '#fd7e14', 'requires_followup' => true],
            ['name' => 'Blank Call',                'color' => '#fd7e14', 'requires_followup' => true],
            ['name' => 'Not reachable',             'color' => '#fd7e14', 'requires_followup' => true],
        ];
    }

    /**
     * Upsert the list and retire everything else. Old rows are deactivated
     * rather than deleted so historical call logs keep their outcome.
     */
    public function run(): void
    {
        $slugs = [];

        foreach (self::definitions() as $i => $item) {
            $slug    = Str::slug($item['name']);
            $slugs[] = $slug;

            $stageId = ! empty($item['stage_slug'])
                ? PipelineStage::where('slug', $item['stage_slug'])->value('id')
                : null;

            Disposition::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'              => $item['name'],
                    'parent_id'         => null,
                    'requires_remark'   => true,
                    'requires_followup' => $item['requires_followup'] ?? false,
                    'is_won'            => false,
                    'is_lost'           => $item['is_lost'] ?? false,
                    'moves_to_stage_id' => $stageId,
                    'sort_order'        => ($i + 1) * 10,
                    'color'             => $item['color'],
                    'is_active'         => true,
                ]
            );
        }

        Disposition::whereNotIn('slug', $slugs)->update(['is_active' => false]);
    }
}
