<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The couple's timeline: wife's and husband's date of birth, marriage date and
 * when they started trying. The screens ask for ages / years, but a date is
 * what gets stored so the number keeps counting up on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->date('wife_dob')->nullable()->after('age');
            $table->date('husband_dob')->nullable()->after('wife_dob');
            $table->date('marriage_date')->nullable()->after('husband_dob');
            $table->date('trying_since')->nullable()->after('marriage_date');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->date('marriage_date')->nullable()->after('husband_age');
            $table->date('trying_since')->nullable()->after('marriage_date');
        });

        // Ages entered before dates existed become a date of birth, so they
        // start ageing instead of staying frozen at the number typed in.
        DB::table('patients')->whereNull('wife_dob')->whereNotNull('wife_age')->where('wife_age', '>', 0)
            ->update(['wife_dob' => DB::raw('DATE_SUB(CURDATE(), INTERVAL wife_age YEAR)')]);
        DB::table('patients')->whereNull('husband_dob')->whereNotNull('husband_age')->where('husband_age', '>', 0)
            ->update(['husband_dob' => DB::raw('DATE_SUB(CURDATE(), INTERVAL husband_age YEAR)')]);
        DB::table('patients')->whereNull('trying_since')->whereNotNull('infertility_duration_months')->where('infertility_duration_months', '>', 0)
            ->update(['trying_since' => DB::raw('DATE_SUB(CURDATE(), INTERVAL infertility_duration_months MONTH)')]);

        // A lead's single "age" becomes the wife's or husband's DOB by gender.
        DB::table('leads')->whereNull('wife_dob')->whereNotNull('age')->where('age', '>', 0)->where('gender', '!=', 'male')
            ->update(['wife_dob' => DB::raw('DATE_SUB(CURDATE(), INTERVAL age YEAR)')]);
        DB::table('leads')->whereNull('husband_dob')->whereNotNull('age')->where('age', '>', 0)->where('gender', 'male')
            ->update(['husband_dob' => DB::raw('DATE_SUB(CURDATE(), INTERVAL age YEAR)')]);
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn(['wife_dob', 'husband_dob', 'marriage_date', 'trying_since']));
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn(['marriage_date', 'trying_since']));
    }
};
