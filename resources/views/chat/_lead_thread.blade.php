{{--
    Embeddable WhatsApp thread for a lead or patient record.

    Reuses the inbox JSON endpoints so behaviour (24h window, templates, media)
    stays identical to the full inbox — this is a compact view of the same data,
    not a second implementation.

    Expects exactly one of $lead / $patient.
--}}
@php
    $subject      = $lead ?? $patient ?? null;
    $subjectType  = isset($lead) ? 'lead' : 'patient';
    $subjectPhone = $subject?->mobile;

    $existing = $subject
        ? \App\Models\Conversation::where('channel', 'whatsapp')
            ->where($subjectType . '_id', $subject->id)
            ->latest('last_message_at')
            ->first()
        : null;
@endphp

<div id="leadChat" data-conversation="{{ $existing?->id }}"
     data-subject-type="{{ $subjectType }}" data-subject-id="{{ $subject?->id }}">

    @if(! $subjectPhone)
        <div class="alert alert-secondary small mb-0">
            <i class="bi bi-telephone-x me-1"></i>
            No mobile number on this record, so WhatsApp messaging is unavailable.
        </div>
    @else
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="small text-muted flex-grow-1" id="lcStatus">
                @if($existing)
                    {{ $existing->isWindowOpen()
                        ? 'Reply window open · closes in ' . $existing->windowRemaining()
                        : 'Reply window closed — send a template to reopen' }}
                @else
                    No conversation yet.
                @endif
            </span>

            @if($existing)
                <a href="{{ route('chat.index', ['conversation' => $existing->id]) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrows-fullscreen me-1"></i>Open in Inbox
                </a>
            @endif
        </div>

        <div id="lcMessages" class="rounded p-3 mb-2"
             style="background:#efeae2;height:320px;overflow-y:auto">
            <div class="text-center text-muted py-5">
                @if($existing)
                    <div class="spinner-border spinner-border-sm"></div>
                @else
                    <i class="bi bi-whatsapp d-block mb-2" style="font-size:2rem;color:#25d366"></i>
                    Start the conversation below.
                @endif
            </div>
        </div>

        @can('conversations.create')
            <div class="input-group">
                <input type="file" id="lcFile" class="d-none"
                       accept="image/jpeg,image/png,image/webp,video/mp4,application/pdf,.doc,.docx,.xls,.xlsx">
                <button class="btn btn-outline-secondary" id="lcAttach" title="Attach a file"><i class="bi bi-paperclip"></i></button>
                <button class="btn btn-outline-secondary" id="lcTemplate" title="Send a template"><i class="bi bi-file-earmark-text"></i></button>
                <input type="text" class="form-control" id="lcInput" placeholder="Type a WhatsApp message…">
                <button class="btn btn-success" id="lcSend"><i class="bi bi-send"></i></button>
            </div>
            <div class="form-text" id="lcFileName"></div>
        @endcan
    @endif
</div>

@push('scripts')
<script>
$(function () {
    const $root = $('#leadChat');
    if (! $root.length) return;

    let conversationId = $root.data('conversation') || null;
    let lastId = 0;
    let pendingFile = null;

    const $messages = $('#lcMessages');

    function esc(v) { return $('<div>').text(v == null ? '' : v).html(); }

    function bubble(m) {
        const out = m.direction === 'outbound';

        let media = '';
        (m.attachments || []).forEach(a => {
            if (a.pending) {
                media = '<div class="small text-muted">Downloading media…</div>';
            } else if (a.url && a.is_image) {
                media = `<a href="${a.url}" target="_blank"><img src="${a.url}" style="max-width:180px;border-radius:6px;display:block;margin-bottom:4px"></a>`;
            } else if (a.url) {
                media = `<a href="${a.url}?download=1" class="d-block small mb-1"><i class="bi ${a.icon} me-1"></i>${esc(a.filename || 'File')}</a>`;
            }
        });

        const bg = m.is_note ? '#fff8e1' : (out ? '#d9fdd3' : '#fff');

        return `
            <div class="d-flex mb-2 ${out ? 'justify-content-end' : ''}">
                <div style="max-width:78%;background:${bg};border-radius:8px;padding:6px 9px;
                            box-shadow:0 1px 1px rgba(0,0,0,.08);font-size:.83rem">
                    ${m.is_note ? '<div class="small fw-bold text-warning-emphasis mb-1"><i class="bi bi-sticky me-1"></i>Internal note</div>' : ''}
                    ${m.template ? `<div class="small text-muted mb-1"><i class="bi bi-file-earmark-text me-1"></i>${esc(m.template)}</div>` : ''}
                    ${media}
                    ${m.body ? `<div>${esc(m.body).replace(/\n/g, '<br>')}</div>` : ''}
                    ${m.status === 'failed' && m.error ? `<div class="small text-danger mt-1">${esc(m.error)}</div>` : ''}
                    <div class="text-end text-muted" style="font-size:.66rem">
                        ${m.time} ${out && !m.is_note ? `<i class="bi ${m.status_icon} ${m.status_class}"></i>` : ''}
                    </div>
                </div>
            </div>`;
    }

    function load(append = false) {
        if (! conversationId) return;

        $.get(`{{ url('chat/conversations') }}/${conversationId}`, append ? { after: lastId } : {})
            .done(res => {
                if (! append) $messages.empty();

                if (! append && ! res.messages.length) {
                    $messages.html('<div class="text-center text-muted py-5 small">No messages yet.</div>');
                }

                res.messages.forEach(m => {
                    $messages.append(bubble(m));
                    lastId = Math.max(lastId, m.id);
                });

                if (res.messages.length || ! append) {
                    $messages.scrollTop($messages[0].scrollHeight);
                }

                const c = res.conversation;
                $('#lcStatus').text(c.window_open
                    ? `Reply window open · closes in ${c.window_left}`
                    : 'Reply window closed — send a template to reopen');
                $('#lcInput').prop('disabled', ! c.window_open)
                    .attr('placeholder', c.window_open ? 'Type a WhatsApp message…' : 'Window closed — use a template');
            });
    }

    // Open (or find) the thread lazily, only when the agent actually sends.
    function ensureConversation() {
        if (conversationId) return $.Deferred().resolve(conversationId).promise();

        return $.post(@json(route('chat.start')), {
            [$root.data('subject-type') + '_id']: $root.data('subject-id'),
        }).then(res => {
            conversationId = res.conversation_id;
            $root.data('conversation', conversationId);
            return conversationId;
        });
    }

    function send() {
        const body = $('#lcInput').val().trim();
        if (! body && ! pendingFile) return;

        $('#lcSend').prop('disabled', true);

        ensureConversation().then(id => {
            if (pendingFile) {
                const data = new FormData();
                data.append('file', pendingFile);
                data.append('caption', body);

                return $.ajax({
                    url: `{{ url('chat/conversations') }}/${id}/media`,
                    method: 'POST', data, processData: false, contentType: false,
                });
            }

            return $.post(`{{ url('chat/conversations') }}/${id}/messages`, { body });
        })
        .done(res => {
            if (res && res.success === false) return toastr.error(res.message);
            $('#lcInput').val('');
            pendingFile = null;
            $('#lcFile').val('');
            $('#lcFileName').text('');
            load();
        })
        .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not send the message.'))
        .always(() => $('#lcSend').prop('disabled', false));
    }

    $('#lcSend').on('click', send);
    $('#lcInput').on('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); send(); } });

    $('#lcAttach').on('click', () => $('#lcFile').click());
    $('#lcFile').on('change', function () {
        if (! this.files.length) return;
        pendingFile = this.files[0];
        $('#lcFileName').text('Attached: ' + pendingFile.name);
    });

    // Templates are the escape hatch when the 24h window has closed.
    $('#lcTemplate').on('click', function () {
        ensureConversation().then(id => {
            window.open(@json(route('chat.index')) + '?conversation=' + id, '_blank');
        });
    });

    // Only fetch once the tab is actually opened.
    $('a[href="#tabWhatsapp"]').on('shown.bs.tab', () => load());

    if ($('#tabWhatsapp').hasClass('active')) load();
});
</script>
@endpush
