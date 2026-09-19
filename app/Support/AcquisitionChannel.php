<?php

namespace App\Support;

/**
 * Works out which marketing channel a person actually came from.
 *
 * The clinic runs Meta and Google side by side and both can land on the same
 * landing page, so the channel cannot be inferred from the page alone. The
 * click identifier is the strongest signal (gclid can only come from Google
 * Ads, a leadgen id can only come from Meta), utm tags are next, and the lead
 * source is the fallback. Resolved once at intake and stored on the lead so
 * reporting never has to re-parse a query string.
 */
final class AcquisitionChannel
{
    public const META_ADS     = 'meta_ads';
    public const GOOGLE_ADS   = 'google_ads';
    public const WHATSAPP     = 'whatsapp';
    public const INSTAGRAM    = 'instagram';
    public const MESSENGER    = 'messenger';
    public const LANDING_PAGE = 'landing_page';
    public const ORGANIC      = 'organic';
    public const REFERRAL     = 'referral';
    public const WALK_IN      = 'walk_in';
    public const IMPORT       = 'import';
    public const API          = 'api';
    public const MANUAL       = 'manual';

    /** channel => [label, icon, colour, is paid media]. */
    private const CHANNELS = [
        self::META_ADS     => ['Meta Ads',      'bi-meta',         '#0866ff', true],
        self::GOOGLE_ADS   => ['Google Ads',    'bi-google',       '#4285f4', true],
        self::WHATSAPP     => ['WhatsApp',      'bi-whatsapp',     '#25d366', false],
        self::INSTAGRAM    => ['Instagram',     'bi-instagram',    '#e1306c', false],
        self::MESSENGER    => ['Messenger',     'bi-messenger',    '#0084ff', false],
        self::LANDING_PAGE => ['Landing Page',  'bi-window',       '#7c3aed', false],
        self::ORGANIC      => ['Organic',       'bi-globe',        '#0f9d58', false],
        self::REFERRAL     => ['Referral',      'bi-person-plus',  '#f59e0b', false],
        self::WALK_IN      => ['Walk-in',       'bi-door-open',    '#64748b', false],
        self::IMPORT       => ['Imported',      'bi-upload',       '#64748b', false],
        self::API          => ['API',           'bi-plug',         '#64748b', false],
        self::MANUAL       => ['Added by team', 'bi-pencil',       '#64748b', false],
    ];

    /**
     * @param  array<string,mixed>  $data  A lead's attributes, or a raw intake payload.
     */
    public static function detect(array $data): string
    {
        // 1. Click identifiers — unambiguous, a network only ever sets its own.
        if (! empty($data['gclid']) || ! empty($data['gbraid']) || ! empty($data['wbraid'])) {
            return self::GOOGLE_ADS;
        }

        if (! empty($data['external_lead_id']) || ! empty($data['ad_id']) || ! empty($data['adset_id']) || ! empty($data['fbclid'])) {
            return self::META_ADS;
        }

        // 2. utm tags, as set by the ad platform's auto-tagging or by hand.
        $source = strtolower(trim((string) ($data['utm_source'] ?? '')));
        $medium = strtolower(trim((string) ($data['utm_medium'] ?? '')));

        if (in_array($source, ['google', 'googleads', 'google_ads', 'google-ads', 'adwords', 'gads', 'youtube'], true)) {
            return self::isPaidMedium($medium) || $source !== 'google' ? self::GOOGLE_ADS : self::ORGANIC;
        }

        if (in_array($source, ['facebook', 'fb', 'meta', 'instagram', 'ig', 'an', 'audiencenetwork'], true)) {
            return self::isPaidMedium($medium) ? self::META_ADS : ($source === 'instagram' || $source === 'ig' ? self::INSTAGRAM : self::META_ADS);
        }

        if (in_array($source, ['whatsapp', 'wa', 'click_to_whatsapp', 'ctwa'], true)) {
            return self::WHATSAPP;
        }

        if ($medium === 'referral' || $source === 'referral') {
            return self::REFERRAL;
        }

        if ($source !== '' || $medium !== '') {
            return self::isPaidMedium($medium) ? self::GOOGLE_ADS : self::LANDING_PAGE;
        }

        // 3. The configured lead source, for channels with no click at all.
        $channel = strtolower(trim((string) ($data['source_channel'] ?? '')));

        return match ($channel) {
            'google'                  => self::GOOGLE_ADS,
            'facebook', 'meta'        => self::META_ADS,
            'instagram'               => self::INSTAGRAM,
            'whatsapp'                => self::WHATSAPP,
            'website'                 => self::LANDING_PAGE,
            'referral'                => self::REFERRAL,
            'walkin', 'walk-in'       => self::WALK_IN,
            'csv'                     => self::IMPORT,
            'api'                     => self::API,
            default                   => self::MANUAL,
        };
    }

    private static function isPaidMedium(string $medium): bool
    {
        return in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'display', 'cpm', 'banner', 'paidsocial', 'paid_social'], true);
    }

    /** @return array<string,string> channel => label. */
    public static function options(): array
    {
        return array_map(fn ($c) => $c[0], self::CHANNELS);
    }

    /** Only the channels that cost money, for spend and CPL reporting. */
    public static function paid(): array
    {
        return array_keys(array_filter(self::CHANNELS, fn ($c) => $c[3]));
    }

    public static function label(?string $channel): string
    {
        return self::CHANNELS[$channel][0] ?? 'Unknown';
    }

    public static function icon(?string $channel): string
    {
        return self::CHANNELS[$channel][1] ?? 'bi-question-circle';
    }

    public static function color(?string $channel): string
    {
        return self::CHANNELS[$channel][2] ?? '#94a3b8';
    }

    public static function isPaid(?string $channel): bool
    {
        return (bool) (self::CHANNELS[$channel][3] ?? false);
    }

    /**
     * The click identifiers worth reading off an inbound request. Kept here so
     * landing pages, the public form and the API all capture the same set.
     */
    public static function clickParameters(): array
    {
        return ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid'];
    }

    public static function utmParameters(): array
    {
        return ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    }
}
