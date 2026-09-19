<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Ages and durations for a couple, derived from stored dates.
 *
 * The forms ask for whole years (wife's age, years married, years trying)
 * because that is what people know off-hand. A date is stored so the number
 * keeps counting up as time passes instead of freezing at what was typed.
 */
trait CoupleDates
{
    public const COUPLE_DATES = ['wife_dob', 'husband_dob', 'marriage_date', 'trying_since'];

    /** Whole years elapsed since a date, or null when unknown. */
    public static function yearsSince(?Carbon $date): ?int
    {
        return $date ? (int) $date->diffInYears(now()) : null;
    }

    /**
     * Turn a number of years into a date. When the years typed match what the
     * existing date already means, the existing (more precise) date is kept.
     */
    public static function dateFromYears(?int $years, ?Carbon $existing = null): ?Carbon
    {
        if ($years === null) {
            return $existing;
        }

        if ($existing && self::yearsSince($existing) === $years) {
            return $existing;
        }

        return now()->startOfDay()->subYears($years);
    }

    public function getWifeAgeYearsAttribute(): ?int
    {
        return self::yearsSince($this->wife_dob);
    }

    public function getHusbandAgeYearsAttribute(): ?int
    {
        return self::yearsSince($this->husband_dob);
    }

    public function getYearsMarriedAttribute(): ?int
    {
        return self::yearsSince($this->marriage_date);
    }

    public function getYearsTryingAttribute(): ?int
    {
        return self::yearsSince($this->trying_since);
    }

    /**
     * Apply year inputs (wife_age, husband_age, years_married, years_trying)
     * from a form onto the model's date columns. Only keys present in the
     * input are touched; a blank clears the date.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, ?Carbon>  The date attributes that were set.
     */
    public function applyCoupleYears(array $input): array
    {
        $map = [
            'wife_age'      => 'wife_dob',
            'husband_age'   => 'husband_dob',
            'years_married' => 'marriage_date',
            'years_trying'  => 'trying_since',
        ];

        $set = [];

        foreach ($map as $yearsKey => $dateKey) {
            if (! array_key_exists($yearsKey, $input)) {
                continue;
            }

            $years = $input[$yearsKey];
            $set[$dateKey] = ($years === null || $years === '')
                ? null
                : self::dateFromYears((int) $years, $this->{$dateKey});
        }

        $this->fill($set);

        return $set;
    }
}
