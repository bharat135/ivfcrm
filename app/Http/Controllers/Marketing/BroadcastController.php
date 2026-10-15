<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppBroadcastJob;
use App\Models\Center;
use App\Models\ChannelAccount;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Patient;
use App\Models\PipelineStage;
use App\Models\WhatsappBroadcast;
use App\Models\WhatsappTemplate;
use App\Services\Meta\WhatsAppService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

/**
 * Bulk template sends to a filtered audience of leads or patients.
 *
 * The audience is resolved to concrete recipient rows at creation time, so the
 * list cannot drift while the broadcast is running and every send is auditable.
 */
class BroadcastController extends Controller
{
    public function __construct(private WhatsAppService $whatsapp) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = WhatsappBroadcast::with(['template:id,name', 'creator:id,name', 'center:id,name'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('whatsapp_template_id'), fn ($q) => $q->where('whatsapp_template_id', $request->integer('whatsapp_template_id')))
                ->when($request->filled('center_id'), fn ($q) => $q->where('center_id', $request->integer('center_id')))
                ->when($request->filled('audience_type'), fn ($q) => $q->where('audience_type', $request->string('audience_type')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')));

            return DataTables::of($query)
                ->addColumn('status_badge', fn (WhatsappBroadcast $b) =>
                    '<span class="badge bg-' . match ($b->status) {
                        'completed' => 'success',
                        'running'   => 'primary',
                        'scheduled' => 'info',
                        'failed'    => 'danger',
                        'cancelled' => 'secondary',
                        default     => 'secondary',
                    } . '">' . e(ucfirst($b->status)) . '</span>')
                ->addColumn('progress', fn (WhatsappBroadcast $b) =>
                    '<div class="progress" style="height:6px"><div class="progress-bar" style="width:' . $b->progressPercent() . '%"></div></div>'
                    . '<small class="text-muted">' . $b->sent_count . '/' . $b->total_recipients . ' sent</small>')
                ->addColumn('delivery', fn (WhatsappBroadcast $b) =>
                    $b->delivered_count . ' delivered · ' . $b->read_count . ' read · ' . $b->failed_count . ' failed')
                ->addColumn('action', fn (WhatsappBroadcast $b) => view('marketing.broadcasts._actions', ['broadcast' => $b])->render())
                ->rawColumns(['status_badge', 'progress', 'action'])
                ->make(true);
        }

        return view('marketing.broadcasts.index', [
            'templates' => WhatsappTemplate::approved()->orderBy('name')->get(),
            'accounts'  => ChannelAccount::where('channel', 'whatsapp')->where('is_active', true)->get(),
            'centers'   => Center::where('is_active', true)->orderBy('name')->get(),
            'sources'   => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'stages'    => PipelineStage::orderBy('sort_order')->get(),
            'leadFields'=> $this->personalisationFields(),
        ]);
    }

    public function show(WhatsappBroadcast $broadcast)
    {
        $broadcast->load('template', 'creator', 'center');

        return view('marketing.broadcasts.show', [
            'broadcast'  => $broadcast,
            'recipients' => $broadcast->recipients()->with('lead:id,name', 'patient:id,wife_name')->paginate(50),
        ]);
    }

    /**
     * Preview how many contacts a filter set matches before committing.
     */
    public function estimate(Request $request): JsonResponse
    {
        $count = $this->audienceQuery($request)->count();

        return response()->json([
            'count'   => $count,
            'message' => $count === 0
                ? 'No contacts match these filters.'
                : "{$count} contact(s) will receive this broadcast.",
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:191'],
            'whatsapp_template_id' => ['required', 'exists:whatsapp_templates,id'],
            'channel_account_id'   => ['nullable', 'exists:channel_accounts,id'],
            'audience_type'        => ['required', 'in:leads,patients'],
            'center_id'            => ['nullable', 'exists:centers,id'],
            'lead_source_id'       => ['nullable', 'exists:lead_sources,id'],
            'stage_id'             => ['nullable', 'exists:pipeline_stages,id'],
            'status'               => ['nullable', 'string'],
            'created_from'         => ['nullable', 'date'],
            'created_to'           => ['nullable', 'date', 'after_or_equal:created_from'],
            'variable_map'         => ['nullable', 'array'],
            'variable_map.*'       => ['nullable', 'string', 'max:191'],
            'scheduled_at'         => ['nullable', 'date', 'after:now'],
            'send_now'             => ['boolean'],
        ]);

        $template = WhatsappTemplate::findOrFail($data['whatsapp_template_id']);

        if (! $template->isApproved()) {
            return response()->json([
                'success' => false,
                'message' => 'Only Meta-approved templates can be broadcast.',
            ], 422);
        }

        // Every placeholder needs a mapping (a contact field or fixed text).
        $map = $data['variable_map'] ?? [];
        $unmapped = [];
        for ($i = 1; $i <= (int) $template->variable_count; $i++) {
            if (trim((string) ($map[$i] ?? $map[(string) $i] ?? '')) === '') {
                $unmapped[] = $i;
            }
        }
        if ($unmapped) {
            return response()->json([
                'success' => false,
                'message' => 'Map every template variable before sending — missing: '
                    . WhatsappTemplate::describeVariables($unmapped) . '.',
            ], 422);
        }

        $contacts = $this->audienceQuery($request)->get();

        if ($contacts->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No contacts match these filters.'], 422);
        }

        $broadcast = WhatsappBroadcast::create([
            'name'                 => $data['name'],
            'whatsapp_template_id' => $template->id,
            'channel_account_id'   => $data['channel_account_id'] ?? null,
            'center_id'            => $data['center_id'] ?? null,
            'created_by'           => Auth::id(),
            'audience_type'        => $data['audience_type'],
            'filters'              => $request->only(['center_id', 'lead_source_id', 'stage_id', 'status', 'created_from', 'created_to']),
            'variable_map'         => $data['variable_map'] ?? [],
            'status'               => $request->boolean('send_now') ? 'scheduled' : ($data['scheduled_at'] ?? null ? 'scheduled' : 'draft'),
            'total_recipients'     => $contacts->count(),
            'scheduled_at'         => $data['scheduled_at'] ?? null,
        ]);

        $this->buildRecipients($broadcast, $contacts, $data['variable_map'] ?? [], $template->variable_count);

        if ($request->boolean('send_now')) {
            SendWhatsAppBroadcastJob::dispatch($broadcast->id);
        } elseif (! empty($data['scheduled_at'])) {
            SendWhatsAppBroadcastJob::dispatch($broadcast->id)->delay($broadcast->scheduled_at);
        }

        return response()->json([
            'success' => true,
            'message' => $request->boolean('send_now')
                ? "Broadcast queued for {$contacts->count()} contact(s)."
                : 'Broadcast saved as ' . $broadcast->status . '.',
            'id' => $broadcast->id,
        ]);
    }

    public function send(WhatsappBroadcast $broadcast): JsonResponse
    {
        if (! $broadcast->isEditable()) {
            return response()->json(['success' => false, 'message' => 'This broadcast has already run.'], 422);
        }

        $broadcast->update(['status' => 'scheduled']);
        SendWhatsAppBroadcastJob::dispatch($broadcast->id);

        return response()->json(['success' => true, 'message' => 'Broadcast queued.']);
    }

    public function cancel(WhatsappBroadcast $broadcast): JsonResponse
    {
        if (in_array($broadcast->status, ['completed', 'cancelled'], true)) {
            return response()->json(['success' => false, 'message' => 'Nothing to cancel.'], 422);
        }

        $broadcast->update(['status' => 'cancelled', 'completed_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Broadcast cancelled. Messages already sent cannot be recalled.',
        ]);
    }

    public function destroy(WhatsappBroadcast $broadcast): JsonResponse
    {
        if ($broadcast->status === 'running') {
            return response()->json(['success' => false, 'message' => 'Cancel the broadcast before deleting it.'], 422);
        }

        $broadcast->delete();

        return response()->json(['success' => true, 'message' => 'Broadcast deleted.']);
    }

    /**
     * Live progress for the detail page's auto-refresh.
     */
    public function progress(WhatsappBroadcast $broadcast): JsonResponse
    {
        return response()->json([
            'status'    => $broadcast->status,
            'percent'   => $broadcast->progressPercent(),
            'sent'      => $broadcast->sent_count,
            'delivered' => $broadcast->delivered_count,
            'read'      => $broadcast->read_count,
            'failed'    => $broadcast->failed_count,
            'total'     => $broadcast->total_recipients,
        ]);
    }

    // -----------------------------------------------------------------
    // Audience
    // -----------------------------------------------------------------

    private function audienceQuery(Request $request)
    {
        if ($request->input('audience_type') === 'patients') {
            return Patient::query()
                ->whereNotNull('mobile')
                ->where('mobile', '!=', '')
                ->when($request->filled('center_id'), fn (Builder $q) => $q->where('center_id', $request->integer('center_id')))
                ->when($request->filled('created_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date('created_from')))
                ->when($request->filled('created_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date('created_to')));
        }

        return Lead::query()
            ->whereNull('merged_into_id')
            ->whereNotNull('mobile')
            ->where('mobile', '!=', '')
            ->when($request->filled('center_id'), fn (Builder $q) => $q->where('center_id', $request->integer('center_id')))
            ->when($request->filled('lead_source_id'), fn (Builder $q) => $q->where('lead_source_id', $request->integer('lead_source_id')))
            ->when($request->filled('stage_id'), fn (Builder $q) => $q->where('stage_id', $request->integer('stage_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('created_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date('created_from')))
            ->when($request->filled('created_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date('created_to')));
    }

    /**
     * Materialise the recipient rows, resolving each template variable from the
     * mapping (a lead attribute name, or a literal string).
     */
    private function buildRecipients(WhatsappBroadcast $broadcast, $contacts, array $map, int $variableCount): void
    {
        $isPatient = $broadcast->audience_type === 'patients';
        $rows = [];
        $seen = [];

        foreach ($contacts as $contact) {
            $mobile = $this->whatsapp->normalizeNumber((string) $contact->mobile);

            // One message per number, even if a contact appears twice.
            if (isset($seen[$mobile]) || strlen($mobile) < 10) {
                continue;
            }
            $seen[$mobile] = true;

            $variables = [];
            for ($i = 1; $i <= $variableCount; $i++) {
                $variables[] = $this->resolveVariable($contact, $map[$i] ?? $map[(string) $i] ?? null);
            }

            $rows[] = [
                'whatsapp_broadcast_id' => $broadcast->id,
                'lead_id'    => $isPatient ? null : $contact->id,
                'patient_id' => $isPatient ? $contact->id : null,
                'mobile'     => $mobile,
                'name'       => $isPatient ? $contact->name : $contact->name,
                'variables'  => json_encode($variables),
                'status'     => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            \App\Models\WhatsappBroadcastRecipient::insert($chunk);
        }

        $broadcast->update(['total_recipients' => count($rows)]);
    }

    /**
     * A mapping entry of "field:name" reads the contact's attribute; anything
     * else is treated as literal text.
     */
    private function resolveVariable($contact, ?string $mapping): string
    {
        if (! $mapping) {
            return '';
        }

        if (str_starts_with($mapping, 'field:')) {
            $attribute = substr($mapping, 6);

            return (string) ($contact->{$attribute} ?? '');
        }

        return $mapping;
    }

    private function personalisationFields(): array
    {
        return [
            'field:name'   => 'Contact name',
            'field:mobile' => 'Mobile',
            'field:email'  => 'Email',
            'field:city'   => 'City',
        ];
    }
}
