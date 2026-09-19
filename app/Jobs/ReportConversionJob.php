<?php

namespace App\Jobs;

use App\Models\Patient;
use App\Services\Google\GoogleAdsConversionService;
use App\Services\Meta\ConversionsApiService;
use App\Support\AcquisitionChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tells the ad network that a person it sold us actually progressed.
 *
 * Runs on the queue because it is reporting, not part of the clinical
 * transaction: a counsellor marking a couple as booked must not wait on, or be
 * blocked by, Meta or Google being slow. Failures are logged and dropped —
 * a missing conversion costs some optimisation quality, never data.
 */
class ReportConversionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(
        public int $patientId,
        public string $stage,
    ) {}

    public function handle(ConversionsApiService $meta, GoogleAdsConversionService $google): void
    {
        $patient = Patient::with(['lead', 'city', 'state'])->find($this->patientId);

        if (! $patient || ! $patient->lead) {
            return;
        }

        // Report to the network that was actually paid for the click. Sending a
        // Google-sourced lead to Meta would only pollute both models.
        $channel = $patient->lead->channel;

        try {
            if ($channel === AcquisitionChannel::GOOGLE_ADS) {
                $google->reportStage($patient, $this->stage);

                return;
            }

            if (in_array($channel, [AcquisitionChannel::META_ADS, AcquisitionChannel::INSTAGRAM, AcquisitionChannel::WHATSAPP], true)) {
                $meta->reportStage($patient, $this->stage);
            }
        } catch (\Throwable $e) {
            Log::warning('Conversion reporting failed', [
                'patient_id' => $this->patientId,
                'stage'      => $this->stage,
                'channel'    => $channel,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
