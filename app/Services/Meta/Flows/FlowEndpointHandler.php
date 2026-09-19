<?php

namespace App\Services\Meta\Flows;

use App\Models\Appointment;
use App\Models\Center;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\WhatsappFlowSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Serves a running Flow between screens.
 *
 * Meta calls the endpoint with an `action` and the current screen's answers;
 * the reply names the *next* screen and the data it needs. Nothing is stored on
 * Meta's side, so any answer needed by a later screen has to be carried forward
 * — either echoed back in the response `data`, or accumulated on the session.
 *
 * Actions:
 *   ping           health check Meta runs before publishing
 *   INIT           the flow just opened; return the first screen
 *   BACK           the user tapped back; re-render the previous screen
 *   data_exchange  a screen was submitted
 */
class FlowEndpointHandler
{
    /** How far ahead appointment slots are offered. */
    private const SLOT_DAYS = 7;

    /**
     * @param  array  $payload  the decrypted request body
     */
    public function handle(array $payload): array
    {
        $action = $payload['action'] ?? null;

        // Meta pings the endpoint before it will let a flow be published.
        if ($action === 'ping') {
            return ['data' => ['status' => 'active']];
        }

        // The client reports client-side errors here; acknowledge and move on.
        if (isset($payload['data']['error'])) {
            Log::warning('WhatsApp flow client error', [
                'error'      => $payload['data']['error'],
                'flow_token' => $payload['flow_token'] ?? null,
            ]);

            return ['data' => ['acknowledged' => true]];
        }

        $session = $this->resolveSession($payload['flow_token'] ?? null);

        return match ($action) {
            'INIT'          => $this->initialScreen($session),
            'BACK'          => $this->back($payload, $session),
            'data_exchange' => $this->exchange($payload, $session),
            default         => throw new FlowEndpointException("Unsupported flow action '{$action}'."),
        };
    }

    private function resolveSession(?string $token): ?WhatsappFlowSession
    {
        if (! $token) {
            return null;
        }

        $session = WhatsappFlowSession::with('flow')->where('flow_token', $token)->first();

        if ($session && $session->status === 'sent') {
            $session->update(['status' => 'opened', 'opened_at' => now()]);
        }

        return $session;
    }

    // -----------------------------------------------------------------
    // Screens
    // -----------------------------------------------------------------

    /**
     * First screen: which centre and doctor.
     */
    private function initialScreen(?WhatsappFlowSession $session): array
    {
        $centerId = $session?->flow?->center_id;

        return [
            'screen' => 'CHOOSE_CENTER',
            'data'   => [
                'centers' => $this->centerOptions($centerId),
                'doctors' => $this->doctorOptions($centerId),
            ],
        ];
    }

    private function back(array $payload, ?WhatsappFlowSession $session): array
    {
        // Re-render whichever screen the user came back to. Only the first is
        // reachable by BACK in this flow, so it is safe to rebuild it.
        return $this->initialScreen($session);
    }

    private function exchange(array $payload, ?WhatsappFlowSession $session): array
    {
        $data   = $payload['data'] ?? [];
        $screen = $data['screen'] ?? $payload['screen'] ?? null;

        // Carry answers forward: Meta only sends the current screen's fields.
        $session?->remember(array_diff_key($data, array_flip(['screen'])));
        $session?->update(['status' => 'in_progress', 'current_screen' => $screen]);

        return match ($screen) {
            'CHOOSE_CENTER' => $this->slotScreen($data, $session),
            'CHOOSE_SLOT'   => $this->confirmScreen($data, $session),
            default         => throw new FlowEndpointException("Unknown screen '{$screen}'."),
        };
    }

    /**
     * Second screen: the times that are genuinely free.
     */
    private function slotScreen(array $data, ?WhatsappFlowSession $session): array
    {
        $centerId = $this->intOrNull($data['center'] ?? null);
        $doctorId = $this->intOrNull($data['doctor'] ?? null);

        $slots = $this->availableSlots($centerId, $doctorId);

        if ($slots === []) {
            // A terminal screen is the only way to say "nothing available"
            // without leaving the user stuck on an empty list.
            return [
                'screen' => 'CONFIRM',
                'data'   => [
                    'summary' => 'We have no open slots in the next '
                        . self::SLOT_DAYS . ' days. Our team will call you to arrange a time.',
                ],
            ];
        }

        return [
            'screen' => 'CHOOSE_SLOT',
            'data'   => [
                'slots'     => $slots,
                // Echoed so the next submission can carry them without a
                // second round trip.
                'full_name' => (string) ($data['full_name'] ?? ''),
                'center'    => (string) ($data['center'] ?? ''),
                'doctor'    => (string) ($data['doctor'] ?? ''),
            ],
        ];
    }

    /**
     * Final screen: book, then confirm.
     */
    private function confirmScreen(array $data, ?WhatsappFlowSession $session): array
    {
        $slot = $data['slot'] ?? null;

        if (! $slot) {
            throw new FlowEndpointException('No slot was selected.');
        }

        try {
            $appointment = $this->book($data, $session);
        } catch (\Throwable $e) {
            Log::error('Flow appointment booking failed', [
                'flow_token' => $session?->flow_token,
                'error'      => $e->getMessage(),
            ]);

            // Never dead-end the patient on an internal failure.
            return [
                'screen' => 'CONFIRM',
                'data'   => ['summary' => 'We could not confirm that slot automatically. Our team will call you shortly.'],
            ];
        }

        return [
            'screen' => 'CONFIRM',
            'data'   => [
                'summary' => $this->confirmationText($appointment),
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Data
    // -----------------------------------------------------------------

    /**
     * @return array<int, array{id:string, title:string}>
     */
    private function centerOptions(?int $only = null): array
    {
        return Center::query()
            ->where('is_active', true)
            ->when($only, fn ($q) => $q->whereKey($only))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name'])
            ->map(fn (Center $c) => ['id' => (string) $c->id, 'title' => $c->name])
            ->all();
    }

    /**
     * @return array<int, array{id:string, title:string}>
     */
    private function doctorOptions(?int $centerId = null): array
    {
        return Doctor::query()
            ->where('is_active', true)
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'specialization'])
            ->map(fn (Doctor $d) => [
                'id'    => (string) $d->id,
                'title' => trim($d->name . ($d->specialization ? ' — ' . $d->specialization : '')),
            ])
            ->all();
    }

    /**
     * Open slots derived from the doctor's weekly availability minus anything
     * already booked.
     *
     * @return array<int, array{id:string, title:string}>
     */
    public function availableSlots(?int $centerId, ?int $doctorId, int $limit = 12): array
    {
        $schedules = DoctorSchedule::query()
            ->where('is_active', true)
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            ->get();

        if ($schedules->isEmpty()) {
            return [];
        }

        $from = now()->addHour()->startOfHour();
        $to   = now()->addDays(self::SLOT_DAYS)->endOfDay();

        // One query for the window, rather than one per candidate slot.
        $taken = Appointment::query()
            ->whereBetween('scheduled_at', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->pluck('scheduled_at')
            ->map(fn ($value) => Carbon::parse($value)->format('Y-m-d H:i'))
            ->flip();

        $slots = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            foreach ($schedules->where('weekday', (int) $day->dayOfWeek) as $schedule) {
                $cursor = $day->copy()->setTimeFromTimeString($schedule->start_time);
                $end    = $day->copy()->setTimeFromTimeString($schedule->end_time);
                $step   = max((int) $schedule->slot_minutes, 5);

                while ($cursor->lt($end)) {
                    if ($cursor->gt($from) && ! $taken->has($cursor->format('Y-m-d H:i'))) {
                        $slots[] = [
                            'id'    => $cursor->format('Y-m-d\TH:i'),
                            'title' => $cursor->format('D j M, g:i A'),
                        ];

                        if (count($slots) >= $limit) {
                            return $slots;
                        }
                    }

                    $cursor->addMinutes($step);
                }
            }
        }

        return $slots;
    }

    private function book(array $data, ?WhatsappFlowSession $session): Appointment
    {
        $when = Carbon::parse($data['slot']);

        $centerId = $this->intOrNull($data['center'] ?? null) ?: Center::where('is_active', true)->value('id');
        $doctorId = $this->intOrNull($data['doctor'] ?? null);

        $appointment = Appointment::create([
            'lead_id'      => $session?->lead_id,
            'patient_id'   => $session?->patient_id,
            'center_id'    => $centerId,
            'doctor_id'    => $doctorId,
            'scheduled_at' => $when,
            'type'         => 'consultation',
            'status'       => 'booked',
            'created_by'   => $session?->sent_by,
            'notes'        => 'Booked by the patient through a WhatsApp flow.',
        ]);

        // Booking is a pipeline event, not just a calendar entry.
        if ($session?->lead_id) {
            $stage = \App\Models\PipelineStage::where('slug', 'consultation-booked')->first();

            if ($stage) {
                \App\Models\Lead::whereKey($session->lead_id)->update([
                    'stage_id'         => $stage->id,
                    'last_activity_at' => now(),
                ]);
            }
        }

        $session?->update(['state' => array_merge($session->state ?? [], [
            'appointment_id' => $appointment->id,
        ])]);

        return $appointment;
    }

    private function confirmationText(Appointment $appointment): string
    {
        $parts = [$appointment->scheduled_at->format('l j F, g:i A')];

        if ($appointment->doctor_id) {
            $name = Doctor::whereKey($appointment->doctor_id)->value('name');
            if ($name) {
                $parts[] = 'with ' . $name;
            }
        }

        if ($appointment->center_id) {
            $center = Center::whereKey($appointment->center_id)->value('name');
            if ($center) {
                $parts[] = 'at ' . $center;
            }
        }

        return implode(' ', $parts) . '. We will send a reminder before your visit.';
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
