<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store every mobile as exactly ten digits (no +91 / 0 prefix) and rebuild
 * the dedup hash from those digits, so "+919876543210" and "9876543210" are
 * finally the same person.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['leads', 'patients'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->select(['id', 'mobile', 'alt_mobile', 'dedup_hash'])
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        $mobile = PhoneNumber::indian($row->mobile);
                        $alt    = PhoneNumber::indian($row->alt_mobile);
                        $hash   = $mobile ? sha1($mobile) : $row->dedup_hash;

                        if ($mobile === $row->mobile && $alt === $row->alt_mobile && $hash === $row->dedup_hash) {
                            continue;
                        }

                        DB::table($table)->where('id', $row->id)->update([
                            'mobile'     => $mobile,
                            'alt_mobile' => $alt,
                            'dedup_hash' => $hash,
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Prefixes cannot be restored; the ten-digit form is canonical.
    }
};
