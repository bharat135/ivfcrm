<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * A follow-up may only be scheduled during calling hours (default 09:00–20:00),
 * because that is when telecallers actually work. The window is configurable via
 * FOLLOWUP_HOURS_START / FOLLOWUP_HOURS_END.
 */
class WithinCallHours implements ValidationRule
{
    public function __construct(
        private int $start = 9,
        private int $end = 20,
    ) {
        $this->start = (int) config('telephony.followup_hours.start', $this->start);
        $this->end   = (int) config('telephony.followup_hours.end', $this->end);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        try {
            $t = Carbon::parse($value);
        } catch (\Throwable) {
            return; // the `date` rule reports the format problem
        }

        $minutes = $t->hour * 60 + $t->minute;

        if ($minutes < $this->start * 60 || $minutes > $this->end * 60) {
            $fail(sprintf(
                'The follow-up time must be between %02d:00 and %02d:00.',
                $this->start,
                $this->end,
            ));
        }
    }
}
