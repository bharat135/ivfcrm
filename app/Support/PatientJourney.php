<?php

namespace App\Support;

/**
 * The one funnel every person in the CRM moves through.
 *
 * Leads and patients are the same record, so there is a single ladder rather
 * than a "lead status" and a separate "patient status" that drift apart. The
 * order matters: the journey only ever moves forward, except into `lost`.
 */
final class PatientJourney
{
    public const ENQUIRY      = 'enquiry';
    public const CONTACTED    = 'contacted';
    public const CONSULTATION = 'consultation';
    public const TREATMENT    = 'treatment';
    public const CLOSED       = 'closed';
    public const LOST         = 'lost';

    /**
     * Stage => [label, rank, bootstrap colour, icon].
     *
     * `lost` deliberately sits outside the ranking; it is an exit, not a step.
     */
    private const STAGES = [
        self::ENQUIRY      => ['Enquiry',      1, 'secondary', 'bi-inbox'],
        self::CONTACTED    => ['Contacted',    2, 'info',      'bi-telephone'],
        self::CONSULTATION => ['Consultation', 3, 'primary',   'bi-clipboard-pulse'],
        self::TREATMENT    => ['Treatment',    4, 'success',   'bi-heart-pulse'],
        self::CLOSED       => ['Closed',       5, 'dark',      'bi-check2-circle'],
        self::LOST         => ['Lost',         0, 'danger',    'bi-x-circle'],
    ];

    /** @return array<string,string> stage => label, in funnel order. */
    public static function options(): array
    {
        return [
            self::ENQUIRY      => 'Enquiry',
            self::CONTACTED    => 'Contacted',
            self::CONSULTATION => 'Consultation',
            self::TREATMENT    => 'Treatment',
            self::CLOSED       => 'Closed',
            self::LOST         => 'Lost',
        ];
    }

    /** The stages that make up the conversion funnel chart, in order. */
    public static function funnel(): array
    {
        return [self::ENQUIRY, self::CONTACTED, self::CONSULTATION, self::TREATMENT, self::CLOSED];
    }

    public static function label(?string $stage): string
    {
        return self::STAGES[$stage][0] ?? 'Enquiry';
    }

    public static function rank(?string $stage): int
    {
        return self::STAGES[$stage][1] ?? 0;
    }

    public static function color(?string $stage): string
    {
        return self::STAGES[$stage][2] ?? 'secondary';
    }

    public static function icon(?string $stage): string
    {
        return self::STAGES[$stage][3] ?? 'bi-inbox';
    }

    public static function isValid(?string $stage): bool
    {
        return $stage !== null && isset(self::STAGES[$stage]);
    }

    /**
     * Whether moving to `$to` is a step forward. `lost` can always be entered,
     * and anything can be re-opened out of `lost`.
     */
    public static function advances(?string $from, string $to): bool
    {
        if ($to === self::LOST || $from === self::LOST || $from === null) {
            return true;
        }

        return self::rank($to) > self::rank($from);
    }
}
