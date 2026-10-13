{{--
    Embeddable WhatsApp threads for a lead or patient record: one chat window
    per distinct number on the record (primary and alternate), side by side.

    Reuses the inbox JSON endpoints so behaviour (24h window, templates, media)
    stays identical to the full inbox — this is a compact view of the same data,
    not a second implementation.

    Expects $whatsappPanels from App\Services\PatientProfile: a list of
    { slot, source, record_id, label, display, conversation }.
--}}
@php
    $panels = collect($whatsappPanels ?? []);
    $canSend = auth()->user()?->can('conversations.create');
    $templates = collect($whatsappTemplates ?? []);
@endphp

<div id="recordChats">
    @if($panels->isEmpty())
        <div class="alert alert-secondary small mb-3">
            <i class="bi bi-telephone-x me-1"></i>
            No mobile number on this record, so WhatsApp messaging is unavailable.
        </div>
    @else
        <div class="row g-3 mb-3">
            @foreach($panels as $panel)
                @php $existing = $panel['conversation']; @endphp
                <div class="{{ $panels->count() > 1 ? 'col-lg-6' : 'col-12' }}">
                    <div class="rc-panel border rounded"
                         data-conversation="{{ $existing?->id }}"
                         data-source="{{ $panel['source'] }}"
                         data-record="{{ $panel['record_id'] }}"
                         data-slot="{{ $panel['slot'] }}"
                         data-display="{{ $panel['display'] }}">

                        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom bg-light rounded-top">
                            <i class="bi bi-whatsapp" style="color:#25d366"></i>
                            <span class="fw-semibold small">{{ $panel['label'] }}</span>
                            <span class="small text-muted text-truncate">{{ $panel['display'] }}</span>
                            <span class="badge text-bg-primary rc-unread {{ ($existing?->unread_count ?? 0) > 0 ? '' : 'd-none' }}">{{ $existing?->unread_count }}</span>
                            <span class="flex-grow-1"></span>
                            <a href="{{ $existing ? route('chat.index', ['conversation' => $existing->id]) : '#' }}"
                               class="btn btn-xs btn-outline-secondary rc-open {{ $existing ? '' : 'd-none' }}" title="Open in Inbox">
                                <i class="bi bi-arrows-fullscreen"></i>
                            </a>
                        </div>

                        <div class="px-3 pt-2 small text-muted rc-status">
                            @if($existing)
                                {{ $existing->isWindowOpen()
                                    ? 'Reply window open · closes in ' . $existing->windowRemaining()
                                    : 'Reply window closed — send a template to reopen' }}
                            @else
                                No conversation yet.
                            @endif
                        </div>

                        <div class="rc-messages mx-3 my-2 rounded p-3"
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

                        @if($canSend)
                            <div class="px-3 pb-3">
                                <div class="input-group input-group-sm">
                                    <input type="file" class="d-none rc-file"
                                           accept="image/jpeg,image/png,image/webp,video/mp4,application/pdf,.doc,.docx,.xls,.xlsx">
                                    <button type="button" class="btn btn-outline-secondary rc-attach" title="Attach a file"><i class="bi bi-paperclip"></i></button>
                                    <button type="button" class="btn btn-outline-secondary rc-template" title="Send a template"><i class="bi bi-file-earmark-text"></i></button>
                                    <input type="text" class="form-control rc-input" placeholder="Type a WhatsApp message…">
                                    <button type="button" class="btn btn-success rc-send"><i class="bi bi-send"></i></button>
                                </div>
                                <div class="form-text rc-filename"></div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@if($canSend && $panels->isNotEmpty())
{{-- Template sender for the embedded threads: the only way to message a
     contact whose 24h window has closed, sent right here on the profile. --}}
<div class="modal fade" id="rcTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Send a template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-3">To: <span id="rcTplTo">—</span></div>
                @if($templates->isEmpty())
                    <div class="alert alert-info mb-0">
                        No approved templates yet.
                        @can('whatsapp_templates.view')
                            <a href="{{ route('marketing.templates.index') }}">Create or sync one</a>.
                        @endcan
                    </div>
                @else
                    <div class="mb-3">
                        <label class="form-label">Template</label>
                        <select class="form-select" id="rcTplSelect">
                            <option value="">Choose a template…</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}"
                                        data-vars="{{ $template->variable_count }}"
                                        data-header="{{ $template->header_format }}"
                                        data-body="{{ e($template->body_text) }}">
                                    {{ $template->name }} ({{ $template->language }}) — {{ $template->category }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3 d-none" id="rcTplHeaderWrap">
                        <label class="form-label">Header media URL</label>
                        <input type="url" class="form-control" id="rcTplHeaderLink" placeholder="https://…">
                        <div class="form-text">Must be a publicly reachable HTTPS link.</div>
                    </div>
                    <div id="rcTplVars"></div>
                    <div class="border rounded p-3 bg-light">
                        <div class="small text-muted mb-1">Preview</div>
                        <div id="rcTplPreview" class="small" style="white-space:pre-wrap">—</div>
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="rcTplSend" @disabled($templates->isEmpty())>
                    <i class="bi bi-send me-1"></i>Send
                </button>
            </div>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
$(function () {
    const $panels = $('#recordChats .rc-panel');
    if (! $panels.length) return;

    const base       = @json(url('chat/conversations'));
    const startUrl   = @json(route('chat.start'));
    const inboxUrl   = @json(route('chat.index'));
    const POLL_MS    = 10000;

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

    // One independent thread per panel: its own conversation id, cursor and
    // pending attachment, all scoped to the panel's root element.
    function panel(root) {
        const $root = $(root);
        const $messages = $root.find('.rc-messages');
        const $input = $root.find('.rc-input');
        const $status = $root.find('.rc-status');

        let conversationId = $root.data('conversation') || null;
        let lastId = 0;
        let pendingFile = null;
        let loaded = false;

        function applyState(c) {
            $status.text(c.window_open
                ? `Reply window open · closes in ${c.window_left}`
                : 'Reply window closed — send a template to reopen');
            $input.prop('disabled', ! c.window_open)
                .attr('placeholder', c.window_open ? 'Type a WhatsApp message…' : 'Window closed — use a template');
            $root.find('.rc-unread').toggleClass('d-none', ! c.unread).text(c.unread || '');
            $root.find('.rc-open').removeClass('d-none').attr('href', inboxUrl + '?conversation=' + c.id);
        }

        function load(append = false) {
            if (! conversationId) return;

            $.get(`${base}/${conversationId}`, append ? { after: lastId } : {})
                .done(res => {
                    if (! append) $messages.empty();

                    if (! append && ! res.messages.length) {
                        $messages.html('<div class="text-center text-muted py-5 small">No messages yet.</div>');
                    } else if (append && res.messages.length && lastId === 0) {
                        $messages.empty();
                    }

                    res.messages.forEach(m => {
                        $messages.append(bubble(m));
                        lastId = Math.max(lastId, m.id);
                    });

                    if (res.messages.length || ! append) {
                        $messages.scrollTop($messages[0].scrollHeight);
                    }

                    applyState(res.conversation);
                    loaded = true;
                });
        }

        // Open (or find) the thread lazily, only when the agent actually sends.
        function ensureConversation() {
            if (conversationId) return $.Deferred().resolve(conversationId).promise();

            return $.post(startUrl, {
                [$root.data('source') + '_id']: $root.data('record'),
                slot: $root.data('slot'),
            }).then(res => {
                conversationId = res.conversation_id;
                $root.data('conversation', conversationId);
                return conversationId;
            });
        }

        function send() {
            const body = $input.val().trim();
            if (! body && ! pendingFile) return;

            const $send = $root.find('.rc-send').prop('disabled', true);

            ensureConversation().then(id => {
                if (pendingFile) {
                    const data = new FormData();
                    data.append('file', pendingFile);
                    data.append('caption', body);

                    return $.ajax({ url: `${base}/${id}/media`, method: 'POST', data, processData: false, contentType: false });
                }

                return $.post(`${base}/${id}/messages`, { body });
            })
            .done(res => {
                if (res && res.success === false) return toastr.error(res.message);
                $input.val('');
                pendingFile = null;
                $root.find('.rc-file').val('');
                $root.find('.rc-filename').text('');
                load();
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not send the message.'))
            .always(() => $send.prop('disabled', false));
        }

        $root.find('.rc-send').on('click', send);
        $input.on('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); send(); } });

        $root.find('.rc-attach').on('click', () => $root.find('.rc-file').click());
        $root.find('.rc-file').on('change', function () {
            if (! this.files.length) return;
            pendingFile = this.files[0];
            $root.find('.rc-filename').text('Attached: ' + pendingFile.name);
        });

        // Templates are the escape hatch when the 24h window has closed.
        // They are sent from this page too — no detour through the inbox.
        $root.find('.rc-template').on('click', function () {
            ensureConversation()
                .then(id => openTemplateModal(id, $root.data('display') || $root.data('slot'), () => load()))
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not open the conversation.'));
        });

        return {
            refresh: () => loaded ? load(true) : load(false),
            focus:   () => $input.trigger('focus'),
        };
    }

    // --- Template modal (one for all panels) ---------------------------
    let tplTarget = null; // { id, reload }

    function openTemplateModal(conversationId, label, reload) {
        const modal = document.getElementById('rcTemplateModal');
        if (! modal) return;

        tplTarget = { id: conversationId, reload };
        $('#rcTplTo').text(label);
        $('#rcTplSelect').val('').trigger('change');
        $('#rcTplHeaderLink').val('');
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    $('#rcTplSelect').on('change', function () {
        const option = this.selectedOptions[0];
        const vars   = parseInt(option?.dataset.vars || 0, 10);
        const header = option?.dataset.header || '';

        $('#rcTplHeaderWrap').toggleClass('d-none', ! ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(header));

        let html = '';
        for (let i = 1; i <= vars; i++) {
            html += `
                <div class="mb-2">
                    <label class="form-label small">Variable @{{${i}}}</label>
                    <input type="text" class="form-control form-control-sm rc-tpl-var" data-index="${i}" placeholder="Value for @{{${i}}}">
                </div>`;
        }
        $('#rcTplVars').html(html);
        updateTemplatePreview();
    });

    $(document).on('input', '.rc-tpl-var', updateTemplatePreview);

    function updateTemplatePreview() {
        const option = document.getElementById('rcTplSelect')?.selectedOptions[0];
        if (! option || ! option.value) return $('#rcTplPreview').text('—');

        let body = option.dataset.body || '';
        $('.rc-tpl-var').each(function () {
            body = body.replaceAll('@{{' + $(this).data('index') + '@}}', $(this).val() || '…');
        });
        $('#rcTplPreview').text(body);
    }

    $('#rcTplSend').on('click', function () {
        const templateId = $('#rcTplSelect').val();
        if (! templateId) return toastr.warning('Choose a template.');
        if (! tplTarget) return;

        const params = {};
        $('.rc-tpl-var').each(function () { params[$(this).data('index')] = $(this).val(); });

        const $btn = $(this).prop('disabled', true);

        $.post(`${base}/${tplTarget.id}/template`, {
            template_id: templateId,
            params,
            header_link: $('#rcTplHeaderLink').val() || null,
        })
        .done(res => {
            if (res && res.success === false) return toastr.error(res.message);
            bootstrap.Modal.getInstance(document.getElementById('rcTemplateModal'))?.hide();
            toastr.success('Template sent.');
            tplTarget.reload();
        })
        .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not send the template.'))
        .always(() => $btn.prop('disabled', false));
    });

    const threads = $panels.map((_, el) => panel(el)).get();

    // Header "WhatsApp" button: jump to the Messages tab and put the cursor
    // in the first thread's composer, so sending starts right here.
    $('#btnProfileWhatsApp').on('click', function () {
        const tab = document.querySelector('[data-bs-target="#tabMessages"]');
        if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
        setTimeout(() => threads[0]?.focus(), 250);
    });

    function refreshAll() {
        if (document.hidden || ! $('#tabMessages').hasClass('active')) return;
        threads.forEach(t => t.refresh());
    }

    // Only fetch once the tab is actually opened, then keep it fresh while
    // the agent is looking at it.
    $('[data-bs-target="#tabMessages"]').on('shown.bs.tab', refreshAll);
    if ($('#tabMessages').hasClass('active')) refreshAll();
    setInterval(refreshAll, POLL_MS);
});
</script>
@endpush
