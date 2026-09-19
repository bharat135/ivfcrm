<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ChannelSpend;
use App\Models\Lead;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleAdsConversionService;
use App\Services\Google\GoogleAdsException;
use App\Services\Google\GoogleAdsInsightService;
use App\Support\AcquisitionChannel;
use App\Support\PatientJourney;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Settings → Integrations → Google Ads.
 *
 * The clinic buys clicks on Google as well as Meta, and both land on the same
 * pages, so the CRM needs its own connection to Google to close the loop:
 * capture the gclid on the way in (the landing page does that), then upload the
 * booking back out so Smart Bidding learns what a good lead looks like.
 *
 * As with Meta, credentials are pasted here rather than deployed; secrets are
 * written through SettingsRepository, which encrypts them, and a blank field
 * means "leave the stored value alone".
 */
class GoogleAdsController extends Controller
{
    public function __construct(
        private SettingsRepository $settings,
        private GoogleAdsClient $client,
        private GoogleAdsConversionService $conversions,
        private GoogleAdsInsightService $insights,
    ) {}

    public function index()
    {
        return view('settings.google-ads.index', [
            'google'   => $this->settings->masked('google_ads'),
            'missing'  => $this->client->missingCredentials(),
            'stages'   => $this->reportableStages(),
            'counts'   => $this->captureCounts(),
            'lastSync' => Cache::get('google_ads.last_sync'),
            'syncError'=> Cache::get('google_ads.last_sync_error'),
            'spend'    => $this->recentSpend(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [
            'client_id'         => ['nullable', 'string', 'max:255'],
            'client_secret'     => ['nullable', 'string', 'max:255'],
            'refresh_token'     => ['nullable', 'string', 'max:1000'],
            'developer_token'   => ['nullable', 'string', 'max:255'],
            'customer_id'       => ['nullable', 'string', 'max:32'],
            'login_customer_id' => ['nullable', 'string', 'max:32'],
            'currency'          => ['nullable', 'string', 'max:5'],
        ];

        // One conversion action and one value per reportable stage.
        foreach (array_keys($this->reportableStages()) as $stage) {
            $rules['action_' . $stage] = ['nullable', 'string', 'max:255'];
            $rules['value_' . $stage]  = ['nullable', 'numeric', 'min:0'];
        }

        $data = $request->validate($rules);

        // A masked secret coming back unchanged must not overwrite the real one.
        foreach ($data as $key => $value) {
            if (is_string($value) && str_starts_with($value, '••••')) {
                unset($data[$key]);
            }
        }

        $this->settings->setMany($data, 'google_ads');

        return response()->json([
            'success' => true,
            'message' => 'Google Ads settings saved.',
        ]);
    }

    /**
     * Prove the credentials work and report what the account actually is, so a
     * wrong customer id is obvious rather than silently uploading nowhere.
     */
    public function test(): JsonResponse
    {
        $missing = $this->client->missingCredentials();

        if ($missing !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Still missing: ' . implode(', ', $missing) . '.',
            ]);
        }

        try {
            return response()->json([
                'success' => true,
                'account' => $this->conversions->accountInfo(),
                'message' => 'Connected to Google Ads.',
            ]);
        } catch (GoogleAdsException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'hint'    => $e->isCredentialProblem()
                    ? 'Check the developer token is approved and the refresh token belongs to a user with access to this customer ID.'
                    : null,
            ]);
        }
    }

    /**
     * The account's conversion actions, so each journey stage can be mapped
     * from a dropdown instead of a pasted resource name.
     */
    public function conversionActions(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'actions' => $this->conversions->conversionActions(),
            ]);
        } catch (GoogleAdsException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Pull spend now rather than waiting for the six-hourly schedule, so the
     * connection can be proved end to end from this screen.
     */
    public function sync(): JsonResponse
    {
        if (! $this->client->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Connect Google Ads first.',
            ]);
        }

        try {
            $result = $this->insights->sync();

            Cache::put('google_ads.last_sync', now(), now()->addDays(7));
            Cache::forget('google_ads.last_sync_error');

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'Synced %d campaign-days, ₹%s of spend, %s to %s.',
                    $result['rows'],
                    number_format($result['spend'], 2),
                    $result['from'],
                    $result['to'],
                ),
            ]);
        } catch (GoogleAdsException $e) {
            Cache::put('google_ads.last_sync_error', $e->getMessage(), now()->addDays(7));

            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Spend already stored for the last 30 days, so the screen can show that
     * the sync is doing something rather than only that it ran.
     *
     * @return array{days:int,total:float}
     */
    private function recentSpend(): array
    {
        $rows = ChannelSpend::where('channel', AcquisitionChannel::GOOGLE_ADS)
            ->where('date', '>=', now()->subDays(30)->toDateString());

        return [
            'days'  => (clone $rows)->distinct('date')->count('date'),
            'total' => round((float) (clone $rows)->sum('spend'), 2),
        ];
    }

    /**
     * Stages worth reporting to Google. `enquiry` is included because many
     * clinics bid on raw leads before they have enough booking volume to
     * optimise on the deeper events.
     *
     * @return array<string,string>
     */
    private function reportableStages(): array
    {
        return [
            PatientJourney::ENQUIRY      => PatientJourney::label(PatientJourney::ENQUIRY),
            PatientJourney::CONTACTED    => PatientJourney::label(PatientJourney::CONTACTED),
            PatientJourney::CONSULTATION => PatientJourney::label(PatientJourney::CONSULTATION),
            PatientJourney::TREATMENT    => PatientJourney::label(PatientJourney::TREATMENT),
        ];
    }

    /**
     * How much Google traffic the CRM is actually capturing. A clinic with
     * Google spend but zero stored click ids has auto-tagging switched off,
     * which is the single most common reason offline conversions never work.
     *
     * @return array{leads:int,with_click_id:int}
     */
    private function captureCounts(): array
    {
        $leads = Lead::where('channel', AcquisitionChannel::GOOGLE_ADS);

        return [
            'leads'         => (clone $leads)->count(),
            'with_click_id' => (clone $leads)->where(function ($q) {
                $q->whereNotNull('gclid')->orWhereNotNull('gbraid')->orWhereNotNull('wbraid');
            })->count(),
        ];
    }
}
