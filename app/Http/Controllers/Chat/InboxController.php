<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Patient;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Services\Chat\ConversationService;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The unified WhatsApp inbox: conversation list, message thread, composer and
 * media handling. All thread interaction is JSON so the UI can poll without a
 * full page reload.
 */
class InboxController extends Controller
{
    public function __construct(
        private ConversationService $conversations,
        private MetaClient $client,
    ) {}

    public function index(Request $request)
    {
        $agents = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $templates = WhatsappTemplate::approved()->orderBy('name')->get();

        return view('chat.index', [
            'agents'        => $agents,
            'templates'     => $templates,
            'configured'    => $this->client->isWhatsAppConfigured(),
            'socialReady'   => app(\App\Services\Meta\MessengerService::class)->isConfigured(),
            'channels'      => [
                'whatsapp'  => ['label' => 'WhatsApp',  'icon' => 'bi-whatsapp'],
                'messenger' => ['label' => 'Messenger', 'icon' => 'bi-messenger'],
                'instagram' => ['label' => 'Instagram', 'icon' => 'bi-instagram'],
            ],
            'selectedId'    => $request->integer('conversation') ?: null,
        ]);
    }

    /**
     * Conversation list for the left pane.
     */
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Conversation::query()
            ->visibleTo($user)
            ->with(['lead:id,name,mobile,status,stage_id', 'patient:id,wife_name,husband_name,patient_code', 'assignee:id,name', 'latestMessage'])
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('unread'), fn (Builder $q) => $q->where('unread_count', '>', 0))
            ->when($request->boolean('mine'), fn (Builder $q) => $q->where('assigned_to', $user->id))
            ->when($request->filled('assigned_to'), fn (Builder $q) => $q->where('assigned_to', $request->integer('assigned_to')))
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $term = '%' . $request->string('q') . '%';
                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('contact_identifier', 'like', $term)
                        ->orWhere('contact_name', 'like', $term)
                        ->orWhere('contact_username', 'like', $term)
                        ->orWhereHas('lead', fn ($l) => $l->where('name', 'like', $term)->orWhere('mobile', 'like', $term));
                });
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        $page = $query->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Conversation $c) => $this->conversationPayload($c)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
                'unread_total' => (clone $query)->getQuery()->getCountForPagination()
                    ? Conversation::visibleTo($user)->where('unread_count', '>', 0)->count()
                    : 0,
            ],
        ]);
    }

    /**
     * Full message thread for the right pane.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($request, $conversation);

        $conversation->load(['lead.stage', 'lead.assignedTo', 'patient', 'assignee', 'channelAccount']);

        $messages = $conversation->messages()
            ->with(['user:id,name', 'messageAttachments'])
            ->when($request->filled('after'), fn ($q) => $q->where('id', '>', $request->integer('after')))
            ->orderBy('id')
            ->get();

        // Opening a thread clears its badge and tells WhatsApp it was read.
        if (! $request->filled('after') && $conversation->unread_count > 0) {
            $this->conversations->markRead($conversation);
            $conversation->refresh();
        }

        return response()->json([
            'conversation' => $this->conversationPayload($conversation, detailed: true),
            'messages'     => $messages->map(fn (Message $m) => $this->messagePayload($m)),
        ]);
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    public function sendText(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($request, $conversation);

        $data = $request->validate([
            'body'     => ['required', 'string', 'max:4096'],
            'reply_to' => ['nullable', 'string'],
        ]);

        try {
            $message = $this->conversations->sendText(
                $conversation,
                $data['body'],
                Auth::id(),
                $data['reply_to'] ?? null,
            );
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $this->messagePayload($message->fresh(['user', 'messageAttachments'])),
        ]);
    }

    /**
     * Image / video / audio / document upload, then send.
     */
    public function sendMedia(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($request, $conversation);

        $maxKb = config('meta.whatsapp.max_upload_mb') * 1024;

        $data = $request->validate([
            'file'    => ['required', 'file', 'max:' . $maxKb],
            'caption' => ['nullable', 'string', 'max:1024'],
        ]);

        $file = $data['file'];
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        if (! $this->isAllowedMime($mime)) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp does not accept this file type (' . $mime . ').',
            ], 422);
        }

        $disk = config('meta.whatsapp.media_disk');
        $path = $file->store(
            trim(config('meta.whatsapp.media_path'), '/') . '/outbound/' . now()->format('Y/m'),
            $disk,
        );

        try {
            $message = $this->conversations->sendMedia(
                $conversation,
                $path,
                $mime,
                $file->getClientOriginalName(),
                $data['caption'] ?? null,
                Auth::id(),
            );
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $this->messagePayload($message->fresh(['user', 'messageAttachments'])),
        ]);
    }

    /**
     * Approved template — the way to reopen a closed 24h window.
     */
    public function sendTemplate(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($request, $conversation);

        $data = $request->validate([
            'template_id' => ['required', 'exists:whatsapp_templates,id'],
            'params'      => ['nullable', 'array'],
            'params.*'    => ['nullable', 'string', 'max:512'],
            'header_link' => ['nullable', 'url'],
        ]);

        if ($conversation->isSocialChannel()) {
            return response()->json([
                'success' => false,
                'message' => 'Templates are a WhatsApp feature. On ' . $conversation->channelLabel()
                    . ' you can reply freely for 7 days after the contact writes.',
            ], 422);
        }

        $template = WhatsappTemplate::findOrFail($data['template_id']);

        if (! $template->isApproved()) {
            return response()->json(['success' => false, 'message' => 'This template is not approved by Meta yet.'], 422);
        }

        $header = [];
        if (! empty($data['header_link']) && $template->header_format && $template->header_format !== 'TEXT') {
            $header = ['type' => strtolower($template->header_format), 'link' => $data['header_link']];
        }

        try {
            $message = $this->conversations->sendTemplate(
                $conversation,
                $template,
                array_values($data['params'] ?? []),
                $header,
                Auth::id(),
            );
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $this->messagePayload($message->fresh(['user', 'messageAttachments'])),
        ]);
    }

    public function addNote(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($request, $conversation);

        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);

        $message = $this->conversations->addNote($conversation, $data['body'], Auth::id());

        return response()->json([
            'success' => true,
            'message' => $this->messagePayload($message->fresh(['user', 'messageAttachments'])),
        ]);
    }

    // -----------------------------------------------------------------
    // Thread management
    // -----------------------------------------------------------------

    /**
     * Open (or reuse) a thread for a lead, a patient, or a raw phone number.
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_id'    => ['nullable', 'exists:leads,id'],
            'patient_id' => ['nullable', 'exists:patients,id'],
            'mobile'     => ['nullable', 'string', 'max:20'],
        ]);

        // Meta forbids business-initiated Messenger and Instagram threads, so
        // "start a chat" is WhatsApp-only by construction.
        $lead    = ! empty($data['lead_id']) ? Lead::find($data['lead_id']) : null;
        $patient = ! empty($data['patient_id']) ? Patient::find($data['patient_id']) : null;

        // validate() only returns keys that were present, so each is optional here.
        $mobile = ($data['mobile'] ?? null) ?: $lead?->mobile ?: $patient?->mobile;

        if (! $mobile) {
            return response()->json(['success' => false, 'message' => 'No mobile number available for this contact.'], 422);
        }

        $conversation = $this->conversations->resolve(
            phone: $mobile,
            channel: 'whatsapp',
            contactName: $lead?->name ?: $patient?->name,
            lead: $lead,
            patient: $patient,
        );

        return response()->json([
            'success'         => true,
            'conversation_id' => $conversation->id,
            'conversation'    => $this->conversationPayload($conversation, detailed: true),
        ]);
    }

    public function assign(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($request, $conversation);

        $data = $request->validate(['assigned_to' => ['nullable', 'exists:users,id']]);

        $this->conversations->assign($conversation, $data['assigned_to'] ?? null);

        return response()->json(['success' => true]);
    }

    public function setStatus(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($request, $conversation);

        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'pending', 'snoozed', 'closed'])],
        ]);

        $this->conversations->setStatus($conversation, $data['status']);

        return response()->json(['success' => true]);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($request, $conversation);

        $this->conversations->markRead($conversation);

        return response()->json(['success' => true]);
    }

    /**
     * Attach an unlinked thread to a lead so its history joins the CRM record.
     */
    public function link(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($request, $conversation);

        $data = $request->validate([
            'lead_id'    => ['nullable', 'exists:leads,id'],
            'patient_id' => ['nullable', 'exists:patients,id'],
        ]);

        $conversation->update(array_filter([
            'lead_id'    => $data['lead_id'] ?? null,
            'patient_id' => $data['patient_id'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json(['success' => true]);
    }

    /**
     * Lightweight poll for the badge counts and "is there anything new".
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Conversation::visibleTo($user);

        return response()->json([
            'unread'       => (clone $query)->where('unread_count', '>', 0)->count(),
            'open'         => (clone $query)->where('status', 'open')->count(),
            'last_message' => (clone $query)->max('last_message_at'),
        ]);
    }

    // -----------------------------------------------------------------
    // Media delivery
    // -----------------------------------------------------------------

    /**
     * Stream an attachment from the private disk. Never expose storage URLs
     * directly — chat media can contain patient reports.
     */
    public function media(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $conversation = $attachment->message?->conversation;

        abort_if(! $conversation, 404);
        $this->authorizeThread($request, $conversation);

        abort_unless($attachment->exists(), 404, 'Media is still downloading or no longer available.');

        $disk = Storage::disk($attachment->disk ?: 'local');

        return $disk->response(
            $attachment->path,
            $attachment->filename ?: basename($attachment->path),
            [
                'Content-Type'  => $attachment->mime ?: 'application/octet-stream',
                'Cache-Control' => 'private, max-age=3600',
            ],
            $request->boolean('download') ? 'attachment' : 'inline',
        );
    }

    // -----------------------------------------------------------------
    // Serialisation
    // -----------------------------------------------------------------

    private function conversationPayload(Conversation $conversation, bool $detailed = false): array
    {
        $latest = $conversation->relationLoaded('latestMessage')
            ? $conversation->latestMessage
            : $conversation->messages()->latest('id')->first();

        $payload = [
            'id'            => $conversation->id,
            'channel'       => $conversation->channel,
            'channel_label' => $conversation->channelLabel(),
            'channel_icon'  => $conversation->channelIcon(),
            'channel_color' => $conversation->channelColor(),
            'avatar'        => $conversation->avatar_url,
            'username'      => $conversation->contact_username,
            'is_social'     => $conversation->isSocialChannel(),
            'needs_tag'     => $conversation->needsHumanAgentTag(),
            'can_template'  => ! $conversation->isSocialChannel(),
            'name'          => $conversation->displayName(),
            'initials'      => $conversation->initials(),
            'identifier'    => $conversation->contact_identifier,
            'status'        => $conversation->status,
            'unread'        => $conversation->unread_count,
            'assigned_to'   => $conversation->assigned_to,
            'assignee'      => $conversation->assignee?->name,
            'lead_id'       => $conversation->lead_id,
            'patient_id'    => $conversation->patient_id,
            'preview'       => $latest?->preview() ?? 'No messages yet',
            'last_at'       => $conversation->last_message_at?->diffForHumans(null, true),
            'last_at_iso'   => $conversation->last_message_at?->toIso8601String(),
            'window_open'   => $conversation->isWindowOpen(),
            'window_left'   => $conversation->windowRemaining(),
        ];

        if ($detailed) {
            $payload['lead'] = $conversation->lead ? [
                'id'     => $conversation->lead->id,
                'name'   => $conversation->lead->name,
                'mobile' => $conversation->lead->mobile,
                'status' => $conversation->lead->status,
                'stage'  => $conversation->lead->stage?->name,
                'owner'  => $conversation->lead->assignedTo?->name,
                'url'    => route('leads.show', $conversation->lead),
            ] : null;

            $payload['patient'] = $conversation->patient ? [
                'id'   => $conversation->patient->id,
                'name' => $conversation->patient->name,
                'code' => $conversation->patient->patient_code,
                'url'  => route('patients.show', $conversation->patient),
            ] : null;
        }

        return $payload;
    }

    private function messagePayload(Message $message): array
    {
        [$icon, $iconClass] = $message->statusIcon();

        return [
            'id'          => $message->id,
            'direction'   => $message->direction,
            'type'        => $message->type,
            'media_type'  => $message->media_type,
            'body'        => $message->body,
            'is_note'     => $message->is_internal_note,
            'status'      => $message->status,
            'status_icon' => $icon,
            'status_class'=> $iconClass,
            'error'       => $message->error_message,
            'template'    => $message->template_name,
            'provider_id' => $message->provider_message_id,
            'reply_to'    => $message->reply_to_message_id,
            'agent'       => $message->user?->name,
            'time'        => $message->created_at->format('g:i A'),
            'date'        => $message->created_at->format('d M Y'),
            'iso'         => $message->created_at->toIso8601String(),
            'attachments' => $message->messageAttachments->map(fn (MessageAttachment $a) => [
                'id'        => $a->id,
                'url'       => $a->download_status === 'downloaded' ? $a->url() : null,
                'type'      => $a->type,
                'mime'      => $a->mime,
                'filename'  => $a->filename,
                'caption'   => $a->caption,
                'size'      => $a->humanSize(),
                'icon'      => $a->icon(),
                'is_image'  => $a->isImage(),
                'is_video'  => $a->isVideo(),
                'is_audio'  => $a->isAudio(),
                'pending'   => $a->download_status === 'pending',
                'failed'    => $a->download_status === 'failed',
            ])->all(),
        ];
    }

    private function isAllowedMime(string $mime): bool
    {
        foreach (config('meta.whatsapp.allowed_mimes') as $mimes) {
            if (in_array($mime, $mimes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Agents may only open threads the visibility scope grants them.
     */
    private function authorizeThread(Request $request, Conversation $conversation): void
    {
        $visible = Conversation::visibleTo($request->user())
            ->whereKey($conversation->id)
            ->exists();

        abort_unless($visible, 403, 'You do not have access to this conversation.');
    }
}
