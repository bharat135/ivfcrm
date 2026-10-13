<?php

namespace App\Http\Controllers\Telephony;

use App\Http\Controllers\Controller;
use App\Models\DialSession;
use App\Models\Lead;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk calling from the leads list: the selected leads become a queue the
 * CRM dials one after another. Each call goes out as an ordinary
 * click-to-call, so the disposition modal, recordings and call log all work
 * exactly as they do for a single call.
 */
class DialSessionController extends Controller
{
    /**
     * Start a run from the selected leads. Leads the agent cannot see, and
     * leads without an Indian mobile, are dropped and reported back.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'lead_ids.*' => ['integer'],
        ]);

        $user = $request->user();

        // Keep the order the agent selected in.
        $wanted = array_values(array_unique(array_map('intval', $data['lead_ids'])));
        $leads  = Lead::query()
            ->whereIn('id', $wanted)
            ->whereNull('merged_into_id')
            ->get(['id', 'mobile', 'assigned_to', 'center_id'])
            ->keyBy('id');

        $queue = [];
        $skipped = 0;

        foreach ($wanted as $id) {
            $lead = $leads->get($id);

            if (! $lead || $user->cannot('view', $lead) || ! PhoneNumber::isIndianMobile($lead->mobile)) {
                $skipped++;

                continue;
            }

            $queue[] = $lead->id;
        }

        if ($queue === []) {
            return response()->json([
                'success' => false,
                'message' => 'None of the selected leads has a 10-digit Indian mobile number you can call.',
            ], 422);
        }

        // One run at a time per agent: starting a new one ends the old one.
        DialSession::query()->active()->where('user_id', $user->id)->get()->each->stop();

        $session = DialSession::create([
            'user_id'    => $user->id,
            'status'     => 'active',
            'lead_ids'   => $queue,
            'cursor'     => 0,
            'started_at' => now(),
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'Bulk calling ' . count($queue) . ' lead' . (count($queue) === 1 ? '' : 's')
                . ($skipped ? " ({$skipped} skipped: not callable)" : '') . '.',
            'total'    => count($queue),
            'skipped'  => $skipped,
            'redirect' => $session->nextUrl(),
        ]);
    }

    /** Polled by the banner on the lead page. */
    public function current(Request $request): JsonResponse
    {
        $session = DialSession::activeFor($request->user());

        return response()->json(['session' => $session ? $this->payload($session) : null]);
    }

    /** Leave the current lead without calling it and move on. */
    public function skip(Request $request): JsonResponse
    {
        $session = DialSession::activeFor($request->user());

        if (! $session) {
            return response()->json(['success' => false, 'message' => 'No bulk-calling run is active.'], 422);
        }

        $session->advance(skipped: true);

        return response()->json([
            'success'  => true,
            'message'  => $session->isActive() ? 'Skipped. Opening the next lead…' : 'That was the last lead in the run.',
            'session'  => $this->payload($session),
            'redirect' => $session->nextUrl(),
        ]);
    }

    public function stop(Request $request): JsonResponse
    {
        $session = DialSession::activeFor($request->user());

        $session?->stop();

        return response()->json([
            'success'  => true,
            'message'  => 'Bulk calling stopped.',
            'redirect' => route('leads.index'),
        ]);
    }

    private function payload(DialSession $session): array
    {
        return [
            'id'       => $session->id,
            'status'   => $session->status,
            'position' => $session->position(),
            'total'    => $session->total(),
            'dialed'   => $session->dialed_count,
            'skipped'  => $session->skipped_count,
            'lead_id'  => $session->currentLeadId(),
            'next_url' => $session->nextUrl(),
        ];
    }
}
