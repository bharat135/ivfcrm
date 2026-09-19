<?php

namespace App\Services\Meta;

use App\Models\Patient;
use App\Support\AcquisitionChannel;
use App\Support\PatientJourney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds Meta custom audiences out of the CRM's own patient list.
 *
 * This is the payoff from leads and patients being one record. Because every
 * enquiry owns a patient file and every patient owns an enquiry, a segment can
 * be defined across both halves of the person at once — "couples who enquired
 * from an ad, were contacted, and never came in" is a marketing audience and a
 * clinical one simultaneously, and neither table could express it alone.
 *
 * Three audiences do most of the work for a fertility clinic:
 *
 *   - `dropped_off`  enquiries that stalled. Retarget them.
 *   - `patients`     couples already in treatment. Exclude them from
 *                    prospecting, so the clinic stops paying to advertise to
 *                    people who are already sitting in its waiting room.
 *   - `best`         couples who reached treatment. Seed a lookalike from these
 *                    and Meta goes looking for more of them.
 *
 * Nothing identifiable leaves the building: every value is SHA-256 hashed by
 * MetaAdsService::hashAudienceRow() before upload.
 */
class AudienceSyncService
{
    /** Meta caps a single /users call at 10,000 rows. */
    private const BATCH_SIZE = 5000;

    /**
     * segment key => [label, description]
     */
    public const SEGMENTS = [
        'dropped_off' => [
            'Stalled enquiries',
            'Enquired but never reached a consultation. The retargeting audience.',
        ],
        'consulted' => [
            'Reached consultation',
            'Came in for a consultation. Worth a nurture campaign if they have not started a cycle.',
        ],
        'patients' => [
            'Current patients',
            'In treatment now. Exclude from prospecting so ad spend is not wasted on them.',
        ],
        'best' => [
            'Best patients',
            'Reached treatment. The seed for a lookalike audience.',
        ],
    ];

    public function __construct(private MetaAdsService $ads, private MetaClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isAdsConfigured();
    }

    /**
     * How many people a segment currently holds, without touching Meta.
     */
    public function size(string $segment, ?int $centerId = null): int
    {
        return $this->query($segment, $centerId)->count();
    }

    /**
     * Create the audience on Meta if needed, then upload everyone in it.
     *
     * @return array{audience_id:string,uploaded:int,batches:int,skipped:int}
     */
    public function sync(string $segment, ?string $audienceId = null, ?int $centerId = null): array
    {
        if (! isset(self::SEGMENTS[$segment])) {
            throw new MetaException("Unknown audience segment [{$segment}].");
        }

        if (! $this->isConfigured()) {
            throw new MetaException('Meta Ads is not configured. Add the access token and ad account under Settings → Integrations.');
        }

        [$label, $description] = self::SEGMENTS[$segment];

        if (! $audienceId) {
            $created = $this->ads->createCustomAudience([
                'name'        => config('app.name') . ' — ' . $label,
                'description' => $description,
            ]);

            $audienceId = $created['id'];
        }

        // One session id across every batch, so Meta reports on the whole file
        // rather than treating each chunk as an unrelated upload.
        $session = (string) random_int(1, PHP_INT_MAX);

        $uploaded = 0;
        $skipped  = 0;
        $batch    = 0;
        $pending  = [];

        $people = $this->query($segment, $centerId)
            ->with(['city:id,name', 'state:id,name'])
            ->select(['id', 'wife_name', 'husband_name', 'mobile', 'email', 'pincode', 'city_id', 'state_id']);

        $people->chunkById(self::BATCH_SIZE, function ($chunk) use (&$pending, &$uploaded, &$skipped, &$batch, $audienceId, $session) {
            foreach ($chunk as $patient) {
                $row = $this->rowFor($patient);

                // A row with neither an email nor a phone can never match, and
                // uploading it only dilutes the audience's reported size.
                if ($row === null) {
                    $skipped++;

                    continue;
                }

                $pending[] = $row;
            }

            if ($pending !== []) {
                $batch++;
                $this->ads->addUsersToAudience($audienceId, $pending, $session, $batch, false);
                $uploaded += count($pending);
                $pending = [];
            }
        });

        // Meta needs to be told the file is complete; without a final batch the
        // audience can sit in "populating" indefinitely.
        if ($batch > 0) {
            $this->ads->addUsersToAudience($audienceId, [], $session, $batch + 1, true);
        }

        Log::info('Custom audience synced', [
            'segment'  => $segment,
            'audience' => $audienceId,
            'uploaded' => $uploaded,
            'skipped'  => $skipped,
        ]);

        return [
            'audience_id' => $audienceId,
            'uploaded'    => $uploaded,
            'batches'     => $batch,
            'skipped'     => $skipped,
        ];
    }

    /**
     * Create a lookalike from an audience that has already been populated.
     */
    public function createLookalike(string $originAudienceId, float $ratio = 0.01, string $country = 'IN'): array
    {
        $origin = $this->ads->getCustomAudience($originAudienceId);

        return $this->ads->createLookalikeAudience([
            'name'               => ($origin['name'] ?? 'Audience') . ' — lookalike ' . round($ratio * 100, 1) . '%',
            'origin_audience_id' => $originAudienceId,
            'ratio'              => $ratio,
            'country'            => $country,
        ]);
    }

    /**
     * The segment definitions themselves.
     *
     * Each one reads across the acquisition and clinical facets of the same
     * person, which is only expressible because they are one record.
     */
    public function query(string $segment, ?int $centerId = null): Builder
    {
        $query = Patient::query()
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            // Somebody with no contact details cannot be matched by Meta.
            ->where(fn ($q) => $q->whereNotNull('mobile')->orWhereNotNull('email'));

        return match ($segment) {
            'dropped_off' => $query
                ->whereIn('journey_stage', [PatientJourney::ENQUIRY, PatientJourney::CONTACTED])
                // Only people the clinic paid to reach are worth retargeting.
                ->whereHas('lead', fn ($q) => $q->whereIn('channel', array_merge(
                    AcquisitionChannel::paid(),
                    [AcquisitionChannel::LANDING_PAGE, AcquisitionChannel::WHATSAPP],
                ))),

            'consulted' => $query->where('journey_stage', PatientJourney::CONSULTATION),

            'patients' => $query->whereIn('journey_stage', [PatientJourney::TREATMENT, PatientJourney::CLOSED]),

            'best' => $query
                ->whereIn('journey_stage', [PatientJourney::TREATMENT, PatientJourney::CLOSED])
                // A lookalike seed is better small and clean than large and old.
                ->where('first_enquiry_at', '>=', now()->subYear()),

            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * One patient as a hashed row, or null when they cannot be matched.
     *
     * @return array<int,string>|null
     */
    private function rowFor(Patient $patient): ?array
    {
        if (blank($patient->mobile) && blank($patient->email)) {
            return null;
        }

        // The wife's name is used because it is the one the clinic almost
        // always has; the husband's is the fallback.
        $name = $patient->wife_name ?: $patient->husband_name;

        return $this->ads->hashAudienceRow([
            'email'      => $patient->email,
            'phone'      => $patient->mobile,
            'first_name' => Str::before(trim((string) $name), ' '),
            'last_name'  => Str::contains(trim((string) $name), ' ') ? Str::afterLast(trim((string) $name), ' ') : null,
            'city'       => $patient->city?->name,
            'state'      => $patient->state?->name,
            'zip'        => $patient->pincode,
            'country'    => 'in',
        ]);
    }
}
