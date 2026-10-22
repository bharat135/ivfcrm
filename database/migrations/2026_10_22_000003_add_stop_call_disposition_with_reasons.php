<?php

use App\Models\Disposition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One "Stop call" disposition with the reason as its sub-disposition, in place
 * of a separate lost disposition per reason. The old ones are deactivated, not
 * deleted, so earlier call logs keep their outcome.
 */
return new class extends Migration
{
    private const REASONS = [
        'Not interested', 'Wrong number', 'Invalid number', 'Do not disturb', 'Fake lead',
        'Not looking for treatment', 'Not inquired', 'Location not preferred',
        'Already pregnant', 'Budget issue', 'Competitor chosen', 'Not planning now',
    ];

    private const RETIRED = [
        'invalid-number', 'do-not-disturb', 'fake-lead', 'not-interested',
        'not-looking-for-treatment', 'not-inquired', 'location-not-preferred', 'wrong-number',
    ];

    public function up(): void
    {
        // A fresh install seeds its dispositions; this is for a live list.
        if (! Schema::hasTable('dispositions') || Disposition::count() === 0) {
            return;
        }

        $stop = Disposition::updateOrCreate(
            ['slug' => 'stop-call'],
            [
                'name'              => 'Stop call',
                'parent_id'         => null,
                'requires_remark'   => true,
                'requires_followup' => false,
                'is_won'            => false,
                'is_lost'           => true,
                'sort_order'        => (int) Disposition::whereNull('parent_id')->max('sort_order') + 10,
                'color'             => '#dc3545',
                'is_active'         => true,
            ]
        );

        foreach (self::REASONS as $i => $reason) {
            Disposition::updateOrCreate(
                ['slug' => 'stop-call-' . Str::slug($reason)],
                [
                    'name'              => $reason,
                    'parent_id'         => $stop->id,
                    'requires_remark'   => true,
                    'requires_followup' => false,
                    'is_won'            => false,
                    'is_lost'           => true,
                    'sort_order'        => ($i + 1) * 10,
                    'color'             => '#dc3545',
                    'is_active'         => true,
                ]
            );
        }

        Disposition::whereNull('parent_id')->whereIn('slug', self::RETIRED)->update(['is_active' => false]);
    }

    public function down(): void
    {
        Disposition::whereNull('parent_id')->whereIn('slug', self::RETIRED)->update(['is_active' => true]);
        Disposition::where('slug', 'like', 'stop-call%')->update(['is_active' => false]);
    }
};
