<?php

namespace App\Services\Google;

use App\Models\Lead;
use App\Models\Patient;
use App\Support\PatientJourney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Uploads offline conversions to Google Ads.
 *
 * The mirror image of the Meta Conversions API service. A Google Ads click
 * that lands on a clinic landing page produces a `gclid` (or `gbraid`/`wbraid`
 * on iOS journeys where no cookie can be set); the CRM stores it on the lead.
 * When that same person later books a consultation or starts a cycle, this
 * sends the outcome back so Smart Bidding stops optimising for form fills and
 * starts optimising for couples who actually turn up.
 *
 * Two upload paths:
 *   - a click conversion, keyed on the stored click id — the accurate one;
 *   - an enhanced conversion, keyed on hashed email/phone, for people whose
 *     click id was never captured (they called the number on the ad instead).
 *
 * Reference: Google Ads API → ConversionUploadService.uploadClickConversions.
 */
class GoogleAdsConversionService
{
    public function __construct(private GoogleAdsClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * The conversion action configured for a journey stage, if any. A stage
     * with no action is simply not reported — the clinic may only care about
     * booked consultations.
     */
    public function actionFor(string $stage): ?string
    {
        $configured = $this->client->credential('action_' . $stage);

        return $configured ?: config("google_ads.conversion_actions.$stage");
    }

    /**
     * Report one person reaching one stage.
     */
    public function reportStage(Patient $patient, string $stage): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $action = $this->actionFor($stage);
        $lead   = $patient->lead;

        if (! $action || ! $lead) {
            return null;
        }

        $conversion = $this->buildConversion($lead, $patient, $action, $stage);

        if (! $conversion) {
            return null;
        }

        return $this->upload([$conversion]);
    }

    /**
     * Send a batch. Google accepts up to 2000 conversions per request; partial
     * failure is enabled so one bad row cannot reject the other 1999.
     */
    public function upload(array $conversions): ?array
    {
        $conversions = array_values(array_filter($conversions));

        if ($conversions === []) {
            return null;
        }

        $customer = $this->client->customerId();

        try {
            $response = $this->client->post("customers/{$customer}:uploadClickConversions", [
                'conversions'    => $conversions,
                'partialFailure' => true,
                'validateOnly'   => false,
            ]);

            $this->logPartialFailures($response);

            return $response;
        } catch (GoogleAdsException $e) {
            Log::warning('Google Ads rejected a conversion batch', [
                'count' => count($conversions),
                'error' => $e->getMessage(),
                'code'  => $e->errorCode(),
            ]);

            return null;
        }
    }

    /**
     * Build one click conversion.
     *
     * @return array<string,mixed>|null  Null when the person cannot be matched
     *                                   to a Google click at all.
     */
    public function buildConversion(Lead $lead, Patient $patient, string $action, string $stage): ?array
    {
        $conversion = [
            'conversionAction'   => $action,
            'conversionDateTime' => $this->conversionDateTime($patient, $stage),

            // Lets Google deduplicate a retried upload instead of double
            // counting the booking.
            'orderId' => 'crm-' . $patient->id . '-' . $stage,
        ];

        $click = $lead->clickIdentifier();

        if ($click && $click['network'] === 'google') {
            // gbraid/wbraid and gclid are mutually exclusive on one conversion.
            $conversion[$click['key']] = $click['value'];
        } elseif (config('google_ads.enhanced_conversions') && ($identifiers = $this->userIdentifiers($lead, $patient))) {
            $conversion['userIdentifiers'] = $identifiers;

            // Required whenever the conversion is matched on identifiers rather
            // than a click id.
            $conversion['conversionEnvironment'] = 'WEB';
        } else {
            return null;
        }

        if ($value = $this->value($stage)) {
            $conversion['conversionValue'] = $value;
            $conversion['currencyCode']    = $this->client->credential('currency', 'INR');
        }

        return $conversion;
    }

    /**
     * Hashed identifiers for enhanced conversions. Google requires SHA-256 of
     * the normalised value, hex encoded — the same discipline as Meta.
     *
     * @return array<int,array<string,string>>
     */
    private function userIdentifiers(Lead $lead, Patient $patient): array
    {
        $identifiers = [];

        if ($email = trim(strtolower((string) ($lead->email ?: $patient->email)))) {
            $identifiers[] = ['hashedEmail' => hash('sha256', $email)];
        }

        if ($phone = $this->e164($lead->mobile ?: $patient->mobile)) {
            $identifiers[] = ['hashedPhoneNumber' => hash('sha256', $phone)];
        }

        return $identifiers;
    }

    /**
     * Google insists on E.164 *with* the plus, unlike Meta.
     */
    private function e164(?string $mobile): ?string
    {
        if (! $mobile) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $mobile);

        if ($digits === '') {
            return null;
        }

        return '+' . (strlen($digits) === 10 ? '91' . $digits : $digits);
    }

    /**
     * Google wants "yyyy-MM-dd HH:mm:ss+|-HH:mm" and refuses a conversion older
     * than the click window, so anything older is clamped forward.
     */
    private function conversionDateTime(Patient $patient, string $stage): string
    {
        $at = $stage === PatientJourney::ENQUIRY
            ? ($patient->first_enquiry_at ?: $patient->created_at)
            : $patient->updated_at;

        $at = $at ? Carbon::parse($at) : now();

        $floor = now()->subDays((int) config('google_ads.max_conversion_age_days', 90))->addHour();

        if ($at->lt($floor)) {
            $at = $floor;
        }

        return $at->format('Y-m-d H:i:sP');
    }

    /**
     * What a stage is worth. Only the stages that represent revenue carry a
     * value; a contacted enquiry is a signal, not money.
     */
    private function value(string $stage): ?float
    {
        $value = (float) $this->client->credential('value_' . $stage, 0);

        return $value > 0 ? $value : null;
    }

    /**
     * Google reports per-row rejections inside a 200 response, so a silent
     * partial failure would otherwise look like success.
     */
    private function logPartialFailures(array $response): void
    {
        $error = $response['partialFailureError'] ?? null;

        if (! $error) {
            return;
        }

        Log::warning('Google Ads accepted the batch but rejected some rows', [
            'message' => $error['message'] ?? null,
        ]);
    }

    // -----------------------------------------------------------------
    // Account metadata, for the settings screen
    // -----------------------------------------------------------------

    /**
     * The conversion actions defined on the account, so the clinic can pick
     * which one each journey stage reports to instead of pasting resource names.
     *
     * @return array<int,array{resource_name:string,name:string,category:string,status:string}>
     */
    public function conversionActions(): array
    {
        $rows = $this->client->search(
            'SELECT conversion_action.resource_name, conversion_action.name, '
            . 'conversion_action.category, conversion_action.status, conversion_action.type '
            . 'FROM conversion_action WHERE conversion_action.status != "REMOVED" '
            . 'ORDER BY conversion_action.name'
        );

        return array_map(fn ($row) => [
            'resource_name' => $row['conversionAction']['resourceName'] ?? '',
            'name'          => $row['conversionAction']['name'] ?? '',
            'category'      => $row['conversionAction']['category'] ?? '',
            'status'        => $row['conversionAction']['status'] ?? '',
        ], $rows);
    }

    /**
     * A cheap call that proves the credentials work, for the "Test connection"
     * button. Returns the account's descriptive name and currency.
     *
     * @return array{name:string,currency:string,timezone:string}
     */
    public function accountInfo(): array
    {
        $rows = $this->client->search(
            'SELECT customer.descriptive_name, customer.currency_code, customer.time_zone FROM customer LIMIT 1'
        );

        $customer = $rows[0]['customer'] ?? [];

        return [
            'name'     => $customer['descriptiveName'] ?? 'Google Ads account',
            'currency' => $customer['currencyCode'] ?? '—',
            'timezone' => $customer['timeZone'] ?? '—',
        ];
    }
}
