<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts "+91 98765 43210", "09876543210" or "9876543210" — anything that
 * reduces to exactly ten digits — and rejects short, foreign or garbled
 * numbers. The value is normalised on save, not here.
 */
class IndianMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! PhoneNumber::isValidIndian((string) $value)) {
            $fail('The :attribute must be a 10-digit Indian number (without +91 or a leading 0).');
        }
    }
}
