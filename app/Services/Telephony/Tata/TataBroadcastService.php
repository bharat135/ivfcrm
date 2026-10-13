<?php

namespace App\Services\Telephony\Tata;

use App\Models\Lead;
use App\Models\TataBroadcast;
use App\Models\TataBroadcastLead;
use App\Models\User;
use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\TataException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Voice broadcasts: a CRM lead segment becomes a Smartflo lead list, which a
 * Smartflo broadcast dials into an IVR / department / agent. Progress comes
 * back through the Tata webhook (broadcast events) and the batch-status API.
 */
class TataBroadcastService
{
    public function __construct(private SmartfloClient $tata) {}

    /**
     * Leads matched by a saved segment definition.
     */
    public function leadsQuery(array $filters): Builder
    {
        return Lead::query()
            ->whereNull('merged_into_id')
            ->whereNotNull('mobile')
            // Voice broadcasts only go to Indian mobiles.
            ->where('mobile', 'regexp', '^[6-9][0-9]{9}$')
            ->when(! empty($filters['stage_id']), fn ($q) => $q->where('stage_id', $filters['stage_id']))
            ->when(! empty($filters['source_id']), fn ($q) => $q->where('lead_source_id', $filters['source_id']))
            ->when(! empty($filters['center_id']), fn ($q) => $q->where('center_id', $filters['center_id']))
            ->when(! empty($filters['assigned_to']), fn ($q) => $q->where('assigned_to', $filters['assigned_to']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['disposition_id']), fn ($q) => $q->where('disposition_id', $filters['disposition_id']))
            ->when(! empty($filters['created_from']), fn ($q) => $q->where('created_at', '>=', Carbon::parse($filters['created_from'])->startOfDay()))
            ->when(! empty($filters['created_to']), fn ($q) => $q->where('created_at', '<=', Carbon::parse($filters['created_to'])->endOfDay()))
            ->when(! empty($filters['lead_ids']), fn ($q) => $q->whereIn('id', (array) $filters['lead_ids']))
            ->orderBy('id');
    }

    public function previewCount(array $filters): int
    {
        return $this->leadsQuery($filters)->count();
    }

    /**
     * Create the broadcast locally, then on Smartflo (list → leads → broadcast).
     */
    public function create(array $attributes, array $filters, User $creator): TataBroadcast
    {
        $broadcast = TataBroadcast::create([
            'name'               => $attributes['name'],
            'description'        => $attributes['description'] ?? null,
            'status'             => 'draft',
            'destination'        => $attributes['destination'],
            'caller_id'          => $attributes['caller_id'],
            'filters'            => $filters,
            'options'            => [
                'timeout'            => (int) ($attributes['timeout'] ?? 45),
                'concurrent_limit'   => (int) ($attributes['concurrent_limit'] ?? 5),
                'number_of_retry'    => (int) ($attributes['number_of_retry'] ?? 1),
                'retry_after_minutes'=> (array) ($attributes['retry_after_minutes'] ?? [30]),
                'time_group'         => $attributes['time_group'] ?? null,
            ],
            'scheduled_start_at' => Carbon::parse($attributes['start_at']),
            'scheduled_end_at'   => Carbon::parse($attributes['end_at']),
            'created_by'         => $creator->id,
        ]);

        // Snapshot the segment so the audience is fixed at creation time.
        $leads = $this->leadsQuery($filters)->get(['id', 'name', 'mobile']);
        $rows = [];

        foreach ($leads as $lead) {
            $mobile = $this->digits($lead->mobile);

            if (! $mobile || strlen($mobile) < 10 || isset($rows[$mobile])) {
                continue;
            }

            $rows[$mobile] = ['lead_id' => $lead->id, 'name' => $lead->name, 'mobile' => $mobile];
        }

        foreach (array_chunk($rows, 500, true) as $chunk) {
            TataBroadcastLead::insert(array_map(fn ($r) => [
                'tata_broadcast_id' => $broadcast->id,
                'lead_id'           => $r['lead_id'],
                'mobile'            => $r['mobile'],
                'status'            => 'queued',
                'created_at'        => now(),
                'updated_at'        => now(),
            ], array_values($chunk)));
        }

        $broadcast->update(['lead_count' => count($rows)]);

        if (count($rows) === 0) {
            $broadcast->update(['status' => 'failed', 'last_error' => 'The segment has no leads with a mobile number.']);

            return $broadcast;
        }

        try {
            $this->pushToSmartflo($broadcast, array_values($rows));
        } catch (TataException $e) {
            $broadcast->update(['status' => 'failed', 'last_error' => Str::limit($e->displayMessage(), 480)]);
        }

        return $broadcast->fresh();
    }

    private function pushToSmartflo(TataBroadcast $broadcast, array $rows): void
    {
        $listName = 'CRM #' . $broadcast->id . ' ' . Str::limit($broadcast->name, 40, '');

        $created = $this->tata->createLeadList($listName, $broadcast->description ?: $broadcast->name, ['name', 'lead_id']);

        // Smartflo may return the id directly; otherwise find the list by name.
        $listId = $created['id'] ?? $created['list_id'] ?? $created['data']['id'] ?? null;
        $listId = $listId !== null ? (string) $listId : $this->findListId($listName);

        if (! $listId) {
            throw new TataException('Smartflo created the lead list but did not return its id. Set it manually from the Smartflo portal.');
        }

        $broadcast->update(['list_id' => $listId]);

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->tata->addLeads($listId, array_map(fn ($r) => [
                'field_0' => $r['mobile'],
                'field_1' => (string) ($r['name'] ?? ''),
                'field_2' => (string) $r['lead_id'],
            ], $chunk));
        }

        $tz = config('telephony.tata.webhook_timezone', 'Asia/Kolkata');
        $options = $broadcast->options ?? [];

        $response = $this->tata->createBroadcast(array_filter([
            'name'                => $broadcast->name,
            'description'         => $broadcast->description ?: $broadcast->name,
            'phone_number_list'   => $listId,
            'destination'         => $broadcast->destination,
            'timeout'             => (string) max((int) ($options['timeout'] ?? 45), 40),
            'concurrent_limit'    => (string) ($options['concurrent_limit'] ?? 5),
            'retry_after_minutes' => array_map('intval', $options['retry_after_minutes'] ?? [30]),
            'caller_id_number'    => $broadcast->caller_id,
            'number_of_retry'     => (string) ($options['number_of_retry'] ?? 1),
            'start_date_time'     => $broadcast->scheduled_start_at->copy()->setTimezone($tz)->format('Y-m-d H:i:s'),
            'end_date_time'       => $broadcast->scheduled_end_at->copy()->setTimezone($tz)->format('Y-m-d H:i:s'),
            'time_group'          => $options['time_group'] ?? null,
            'dialstatus'          => [1, 2, 3, 4, 5],
            'retry_dialstatus'    => [1, 3],
        ], fn ($v) => $v !== null && $v !== ''));

        $smartfloId = $response['id'] ?? $response['broadcast_id'] ?? $response['data']['id'] ?? null;

        if (! $smartfloId) {
            // Look it up by list id in the account's broadcasts.
            foreach ($this->tata->broadcasts(1, 100) as $row) {
                if ((string) ($row['list_id'] ?? '') === (string) $listId) {
                    $smartfloId = $row['id'] ?? null;
                    break;
                }
            }
        }

        $broadcast->update([
            'smartflo_id'    => $smartfloId ? (string) $smartfloId : null,
            'status'         => $smartfloId ? 'created' : 'failed',
            'last_error'     => $smartfloId ? null : 'Broadcast was submitted but Smartflo did not return an id.',
            'last_synced_at' => now(),
            'stats'          => ['response' => $response],
        ]);
    }

    private function findListId(string $name): ?string
    {
        foreach ($this->tata->leadLists() as $list) {
            if ((string) ($list['name'] ?? '') === $name && isset($list['id'])) {
                return (string) $list['id'];
            }
        }

        return null;
    }

    public function control(TataBroadcast $broadcast, string $action): TataBroadcast
    {
        if (! $broadcast->smartflo_id) {
            throw new TataException('This broadcast has no Smartflo id, so it cannot be controlled.');
        }

        $this->tata->controlBroadcast($broadcast->smartflo_id, $action);

        $broadcast->update([
            'status' => match ($action) {
                'start', 'resume' => 'running',
                'pause'           => 'paused',
                'end'             => 'ended',
            },
            'last_error'     => null,
            'last_synced_at' => now(),
        ]);

        return $broadcast;
    }

    /**
     * Pull the broadcast's status plus batch status for every batch we know of.
     */
    public function refresh(TataBroadcast $broadcast): TataBroadcast
    {
        if (! $broadcast->smartflo_id) {
            return $broadcast;
        }

        $details = $this->tata->broadcast($broadcast->smartflo_id);

        $stats = $broadcast->stats ?? [];
        $stats['details'] = $details;

        $remoteStatus = $details['status'] ?? null;

        $status = match (true) {
            $remoteStatus === 1 || $remoteStatus === '1'   => 'running',
            $remoteStatus === 2 || $remoteStatus === '2'   => 'paused',
            $remoteStatus === 0 || $remoteStatus === '0'   => $broadcast->status === 'running' ? 'ended' : $broadcast->status,
            default                                        => $broadcast->status,
        };

        $batches = collect($broadcast->batch_ids ?? [])
            ->merge((array) ($details['batch_ids'] ?? $details['batches'] ?? []))
            ->map(fn ($b) => is_array($b) ? ($b['id'] ?? $b['batch_id'] ?? null) : $b)
            ->filter()
            ->unique()
            ->values();

        // Without a distinct batch id, Smartflo often keys batch status on the broadcast id itself.
        if ($batches->isEmpty()) {
            $batches = collect([$broadcast->smartflo_id]);
        }

        $stats['batches'] = [];

        foreach ($batches as $batchId) {
            try {
                $stats['batches'][(string) $batchId] = $this->tata->broadcastBatchStatus((string) $batchId);
            } catch (TataException $e) {
                $stats['batches'][(string) $batchId] = ['success' => false, 'message' => $e->displayMessage()];
            }
        }

        $stats['leads'] = [
            'queued'   => $broadcast->leads()->where('status', 'queued')->count(),
            'dialed'   => $broadcast->leads()->where('status', 'dialed')->count(),
            'answered' => $broadcast->leads()->where('status', 'answered')->count(),
            'missed'   => $broadcast->leads()->where('status', 'missed')->count(),
            'failed'   => $broadcast->leads()->where('status', 'failed')->count(),
        ];

        $broadcast->update([
            'status'         => $status,
            'batch_ids'      => $batches->all(),
            'stats'          => $stats,
            'last_synced_at' => now(),
            'last_error'     => null,
        ]);

        return $broadcast->fresh();
    }

    /**
     * Apply a Smartflo webhook event that carries a broadcast id to the
     * matching lead row.
     */
    public function applyEvent(array $data): ?TataBroadcastLead
    {
        $raw = $data['raw'] ?? [];
        $broadcastId = $raw['broadcast_id'] ?? $raw['campaign_id'] ?? null;

        if (! $broadcastId) {
            return null;
        }

        $broadcast = TataBroadcast::where('smartflo_id', (string) $broadcastId)->first();

        if (! $broadcast) {
            return null;
        }

        if (! empty($raw['batch_id'])) {
            $ids = collect($broadcast->batch_ids ?? [])->push((string) $raw['batch_id'])->unique()->values()->all();
            $broadcast->update(['batch_ids' => $ids]);
        }

        $customer = $this->digits($data['to_number'] ?? $data['from_number'] ?? null);

        if (! $customer) {
            return null;
        }

        $row = $broadcast->leads()->where('mobile', 'like', '%' . substr($customer, -10))->first();

        if (! $row) {
            return null;
        }

        $answered = in_array($data['call_status'] ?? null, ['answered', 'connected', 'completed'], true);
        $ended = isset($data['end_stamp']) || isset($data['call_status']);

        $row->update([
            'status'      => $ended ? ($answered ? 'answered' : 'missed') : 'dialed',
            'dial_status' => $data['call_status'] ?? $data['hangup_cause'] ?? $row->dial_status,
            'attempts'    => $row->attempts + ($ended ? 1 : 0),
            'call_log_id' => $data['call_log_id'] ?? $row->call_log_id,
            'last_event'  => $raw,
        ]);

        if ($ended) {
            $broadcast->update(['last_synced_at' => now()]);
        }

        return $row;
    }

    private function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        return $digits === '' ? null : $digits;
    }
}
