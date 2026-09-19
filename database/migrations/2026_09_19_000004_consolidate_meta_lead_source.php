<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One lead source for the whole Meta family. Facebook Ads / Organic and
 * Instagram Ads / Organic are folded into a single "Meta" source, every row
 * that pointed at them is re-pointed, and the old sources are removed.
 */
return new class extends Migration
{
    /** Tables with a lead_source_id column that may reference the old sources. */
    private const TABLES = [
        'leads', 'campaigns', 'meta_lead_forms', 'landing_pages', 'whatsapp_flows', 'lead_imports', 'channel_accounts',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('lead_sources')) {
            return;
        }

        $now  = now();
        $meta = DB::table('lead_sources')->where('slug', 'meta')->first();

        if (! $meta) {
            $id = DB::table('lead_sources')->insertGetId([
                'name'       => 'Meta',
                'slug'       => 'meta',
                'channel'    => 'meta',
                'is_paid'    => true,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $id = $meta->id;
            DB::table('lead_sources')->where('id', $id)->update(['channel' => 'meta', 'is_active' => true, 'updated_at' => $now]);
        }

        $old = DB::table('lead_sources')
            ->where('id', '!=', $id)
            ->where(fn ($q) => $q->whereIn('channel', ['facebook', 'instagram'])
                ->orWhereIn('slug', ['facebook-lead-ads', 'facebook-ads', 'facebook-organic', 'instagram-ads', 'instagram-organic']))
            ->pluck('id')
            ->all();

        if (! $old) {
            return;
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'lead_source_id')) {
                DB::table($table)->whereIn('lead_source_id', $old)->update(['lead_source_id' => $id]);
            }
        }

        DB::table('lead_sources')->whereIn('id', $old)->delete();
    }

    public function down(): void
    {
        // The old sources cannot be reconstructed per lead; nothing to undo.
    }
};
