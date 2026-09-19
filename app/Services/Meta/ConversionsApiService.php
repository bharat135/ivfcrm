<?php

namespace App\Services\Meta;

use App\Models\Lead;
use App\Models\Patient;
use App\Support\PatientJourney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Meta Conversions API — reports what actually happened to a lead.
 *
 * Meta's auction only optimises for outcomes it can see. Left alone it sees a
 * form submission and nothing else, so it happily buys more of whatever
 * produces cheap form fills — which for a fertility clinic is mostly noise.
 * Sending the rest of the journey back (the couple answered the phone, came in
 * for a consultation, started a cycle) is what teaches it to find people who
 * actually book.
 *
 * The link back to the original click is:
 *   - `fbc`       rebuilt from the fbclid captured on the landing page, or
 *   - `ctwa_clid` for a click-to-WhatsApp ad, or
 *   - hashed phone and email, which Meta matches against its own graph.
 *
 * Every identifier except the click ids is SHA-256 hashed before it leaves the
 * building; Meta requires it and it means no raw patient PII is transmitted.
 *
 * Reference: Marketing API → Conversions API.
 */
class ConversionsApiService
{
    /**
     * CRM journey stage => the standard Meta event that represents it.
     *
     * These are Meta's standard event names on purpose: custom events cannot
     * be used as an optimisation goal without extra setup in Events Manager.
     */
    private const STAGE_EVENTS = [
        PatientJourney::ENQUIRY      => 'Lead',
        PatientJourney::CONTACTED    => 'Contact',
        PatientJourney::CONSULTATION => 'Schedule',
        PatientJourney::TREATMENT    => 'Purchase',
        PatientJourney::CLOSED       => 'Purchase',
    ];

    public function __construct(private MetaClient $client) {}

    /**
     * The pixel or WhatsApp dataset that receives events.
     */
    public function datasetId(): ?string
    {
        return $this->client->credential('dataset_id') ?: $this->client->credential('pixel_id');
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured() && ! empty($this->datasetId());
    }

    /**
     * Report a journey stage for one person.
     *
     * Returns null when there is nothing to report or nothing configured, so
     * callers can fire this optimistically without guarding every call.
     */
    public function reportStage(Patient $patient, string $stage): ?array
    {
        if (! $this->isConfigured() || ! isset(self::STAGE_EVENTS[$stage])) {
            return null;
        }

        $lead = $patient->lead;

        // Only ad-sourced people are worth reporting: an event with no click id
        // and no match key teaches the algorithm nothing.
        if (! $lead || ! $this->hasMatchKeys($lead, $patient)) {
            return null;
        }

        return $this->send([$this->buildEvent($patient, $lead, self::STAGE_EVENTS[$stage], $stage)]);
    }

    /**
     * Send a batch of already-built events. Meta accepts up to 1000 per call.
     */
    public function send(array $events, bool $test = false): ?array
    {
        $events = array_values(array_filter($events));

        if ($events === []) {
            return null;
        }

        $payload = ['data' => json_encode($events)];

        if ($test && ($code = $this->client->credential('test_event_code'))) {
            $payload['test_event_code'] = $code;
        }

        try {
            return $this->client->post($this->datasetId() . '/events', $payload);
        } catch (MetaException $e) {
            // A rejected conversion must never break the clinical workflow that
            // triggered it; it is reporting, not a transaction.
            Log::warning('Conversions API rejected an event batch', [
                'count' => count($events),
                'error' => $e->getMessage(),
                'code'  => $e->errorCode,
            ]);

            return null;
        }
    }

    /**
     * Build one server event for a person reaching a stage.
     *
     * @return array<string,mixed>
     */
    public function buildEvent(Patient $patient, Lead $lead, string $eventName, string $stage): array
    {
        $fromMessaging = $this->ctwaClickId($lead) !== null;

        return array_filter([
            'event_name' => $eventName,

            // When the stage was reached, not when this job happened to run.
            'event_time' => $this->eventTime($patient, $stage),

            // Deterministic, so a retried job is deduplicated by Meta rather
            // than counted twice.
            'event_id' => 'crm.' . $patient->id . '.' . $stage,

            'action_source' => $fromMessaging ? 'business_messaging' : 'system_generated',

            'user_data'   => $this->userData($lead, $patient),
            'custom_data' => $this->customData($lead, $patient, $stage),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Identity, hashed. Meta matches on any of these; more keys means a higher
     * match rate, which is why the couple's city and state go too.
     *
     * @return array<string,mixed>
     */
    private function userData(Lead $lead, Patient $patient): array
    {
        $data = array_filter([
            'em' => $this->hashList($lead->email ?: $patient->email),
            'ph' => $this->hashList($this->phone($lead->mobile ?: $patient->mobile)),
            'fn' => $this->hashList($this->firstName($lead->name ?: $patient->wife_name)),
            'ln' => $this->hashList($this->lastName($lead->name ?: $patient->wife_name)),
            'ct' => $this->hashList($patient->city?->name ?? null),
            'st' => $this->hashList($patient->state?->name ?? null),
            'zp' => $this->hashList($lead->pincode ?: $patient->pincode),
            'country' => $this->hashList('in'),
        ], fn ($v) => $v !== null && $v !== []);

        // Click identifiers are sent raw — they are Meta's own opaque tokens,
        // not personal data, and hashing them would make them unmatchable.
        if ($clid = $this->ctwaClickId($lead)) {
            $data['ctwa_clid'] = $clid;
        }

        if ($fbc = $this->fbc($lead)) {
            $data['fbc'] = $fbc;
        }

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function customData(Lead $lead, Patient $patient, string $stage): array
    {
        $data = [
            // Tells Meta these came out of a CRM rather than a web page, which
            // is what makes them eligible for lead-quality optimisation.
            'lead_event_source' => config('app.name', 'CRM'),
            'event_source'      => 'crm',
        ];

        if ($stage === PatientJourney::TREATMENT || $stage === PatientJourney::CLOSED) {
            $value = (float) $this->client->credential('treatment_value', 0);

            if ($value > 0) {
                $data['value']    = $value;
                $data['currency'] = $this->client->credential('currency', 'INR');
            }
        }

        if ($lead->score_band) {
            $data['lead_quality'] = $lead->score_band;
        }

        return $data;
    }

    /**
     * The moment the stage was actually reached. Meta rejects events dated more
     * than seven days back, so anything older is clamped rather than dropped —
     * a slightly late event still carries the signal.
     */
    private function eventTime(Patient $patient, string $stage): int
    {
        $at = $stage === PatientJourney::ENQUIRY
            ? ($patient->first_enquiry_at ?: $patient->created_at)
            : $patient->updated_at;

        $at = $at ? Carbon::parse($at) : now();

        $floor = now()->subDays(6)->subHours(23);

        return ($at->lt($floor) ? $floor : $at)->getTimestamp();
    }

    private function hasMatchKeys(Lead $lead, Patient $patient): bool
    {
        return (bool) ($this->ctwaClickId($lead)
            || $this->fbc($lead)
            || $lead->mobile
            || $patient->mobile
            || $lead->email);
    }

    /**
     * `fbc` is a structured cookie value, not the bare click id:
     * fb.<subdomain index>.<creation time ms>.<fbclid>
     */
    private function fbc(Lead $lead): ?string
    {
        if (! $lead->fbclid) {
            return null;
        }

        $created = ($lead->lead_date ?: $lead->created_at ?: now())->getTimestampMs();

        return 'fb.1.' . $created . '.' . $lead->fbclid;
    }

    /**
     * A click-to-WhatsApp click id, stashed on the lead by the attributor.
     */
    private function ctwaClickId(Lead $lead): ?string
    {
        $referral = $lead->extra['whatsapp_referral'] ?? [];

        return $referral['ctwa_clid'] ?? null;
    }

    /**
     * Meta wants the number in E.164 without the leading plus. Indian numbers
     * are usually stored bare, so the country code is added when it is missing.
     */
    private function phone(?string $mobile): ?string
    {
        if (! $mobile) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $mobile);

        if ($digits === '') {
            return null;
        }

        return strlen($digits) === 10 ? '91' . $digits : $digits;
    }

    private function firstName(?string $name): ?string
    {
        return $name ? (preg_split('/\s+/', trim($name))[0] ?? null) : null;
    }

    private function lastName(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($name));

        return count($parts) > 1 ? end($parts) : null;
    }

    /**
     * Meta expects each user_data key as an array of SHA-256 hashes of the
     * normalised (lowercased, trimmed) value.
     *
     * @return array<int,string>|null
     */
    private function hashList(?string $value): ?array
    {
        $value = trim(strtolower((string) $value));

        return $value === '' ? null : [hash('sha256', $value)];
    }
}
