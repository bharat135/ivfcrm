@extends('layouts.app')

@section('title', 'WhatsApp Inbox')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item active">WhatsApp Inbox</li>
@endsection

@push('styles')
<style>
    /* Three-pane inbox that fills the viewport below the topbar.
       Built entirely on the design-system tokens so it tracks light/dark. */
    #inbox {
        display: grid;
        grid-template-columns: 300px 1fr 272px;
        height: calc(100vh - var(--topbar-height) - 44px);
        border: 1px solid var(--fui-border-subtle);
        border-radius: var(--fui-radius-lg);
        overflow: hidden;
        background: var(--fui-surface);
    }

    #convList { border-right: 1px solid var(--fui-border-subtle); display: flex; flex-direction: column; min-width: 0; }
    #threadPane { display: flex; flex-direction: column; min-width: 0; }
    #contextPane { border-left: 1px solid var(--fui-border-subtle); overflow-y: auto; background: var(--fui-surface-subtle); }

    .conv-scroll { overflow-y: auto; flex: 1; }

    .conv-item {
        display: flex; gap: 9px;
        padding: 9px 12px;
        cursor: pointer;
        border-bottom: 1px solid var(--fui-border-subtle);
        transition: background .12s ease;
    }
    .conv-item:hover { background: var(--fui-surface-subtle); }
    .conv-item.active { background: var(--fui-surface-muted); box-shadow: inset 2px 0 0 var(--fui-gray-950); }

    .avatar {
        width: 32px; height: 32px; flex: 0 0 32px;
        border-radius: var(--fui-radius);
        background: var(--fui-gray-200);
        color: var(--fui-ink-muted);
        display: flex; align-items: center; justify-content: center;
        font-weight: 500; font-size: var(--fui-text-sm);
        object-fit: cover;
    }

    /* The channel dot is the only place brand colour appears; it is what lets
       an agent tell three channels apart at a glance in one list. */
    .avatar-wrap { position: relative; flex: 0 0 32px; }
    .channel-dot {
        position: absolute; right: -2px; bottom: -2px;
        width: 11px; height: 11px;
        border-radius: 999px;
        border: 2px solid var(--fui-surface);
    }

    .tag-notice {
        background: var(--fui-blue-50);
        border-top: 1px solid var(--fui-blue-100);
        color: var(--fui-blue-600);
        padding: 7px 12px;
        font-size: var(--fui-text-sm);
        display: flex; align-items: center; gap: 6px;
    }

    /* Message thread — a plain tinted canvas, no wallpaper texture. */
    #messages {
        flex: 1; overflow-y: auto; padding: 16px 18px;
        background: var(--fui-surface-subtle);
    }

    .msg-row { display: flex; margin-bottom: 6px; }
    .msg-row.out { justify-content: flex-end; }

    .bubble {
        max-width: 66%;
        padding: 7px 10px 5px;
        border-radius: var(--fui-radius-lg);
        background: var(--fui-surface);
        border: 1px solid var(--fui-border-subtle);
        font-size: var(--fui-text-base);
        line-height: 1.5;
        word-wrap: break-word;
    }
    .msg-row.out .bubble {
        background: var(--fui-gray-950);
        border-color: var(--fui-gray-950);
        color: var(--fui-ink-invert);
    }
    .msg-row.out .bubble a { color: inherit; text-decoration: underline; }
    .bubble.note {
        background: var(--fui-amber-50);
        border-color: var(--fui-amber-100);
        color: var(--fui-amber-700);
    }
    .bubble.failed { background: var(--fui-red-50); border-color: var(--fui-red-100); color: var(--fui-red-700); }

    .bubble .meta {
        font-size: 10.5px;
        opacity: .65;
        margin-top: 3px;
        display: flex; align-items: center; gap: 4px; justify-content: flex-end;
    }

    .bubble img.media { max-width: 240px; border-radius: var(--fui-radius); display: block; margin-bottom: 4px; cursor: pointer; }
    .bubble video.media { max-width: 240px; border-radius: var(--fui-radius); display: block; margin-bottom: 4px; }

    .day-divider { text-align: center; margin: 14px 0 10px; }
    .day-divider span {
        background: var(--fui-surface);
        border: 1px solid var(--fui-border-subtle);
        padding: 2px 10px;
        border-radius: 999px;
        font-size: var(--fui-text-xs);
        color: var(--fui-ink-subtle);
    }

    /* Composer */
    #composer { border-top: 1px solid var(--fui-border-subtle); padding: 10px 12px; background: var(--fui-surface); }
    #composer textarea { resize: none; max-height: 120px; }

    .window-warning {
        background: var(--fui-amber-50);
        border-top: 1px solid var(--fui-amber-100);
        color: var(--fui-amber-700);
        padding: 8px 12px;
        font-size: var(--fui-text-sm);
        display: flex; align-items: center; gap: 6px;
    }

    #dropZone.dragging { outline: 2px dashed var(--fui-blue-500); outline-offset: -5px; border-radius: var(--fui-radius); }

    .attach-preview {
        display: flex; align-items: center; gap: 8px;
        padding: 6px 10px;
        background: var(--fui-surface-muted);
        border-radius: var(--fui-radius);
        margin-bottom: 8px;
        font-size: var(--fui-text-sm);
    }

    @media (max-width: 1200px) { #inbox { grid-template-columns: 280px 1fr; } #contextPane { display: none; } }
    @media (max-width: 768px)  { #inbox { grid-template-columns: 1fr; } #convList.hide-mobile { display: none; } }
</style>
@endpush

@section('content')

@unless($configured && $socialReady)
    <div class="alert alert-warning d-flex align-items-start">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">
            @unless($configured)
                WhatsApp is not connected — add your access token and phone number ID.
            @endunless
            @unless($socialReady)
                {{ $configured ? 'Messenger and Instagram need' : 'Messenger and Instagram also need' }}
                a Facebook Page connected, with the <code>page</code> and <code>instagram</code>
                webhook fields subscribed.
            @endunless
        </div>
        @can('integrations.view')
            <a href="{{ route('settings.integrations.index') }}" class="btn btn-sm btn-warning">Open settings</a>
        @endcan
    </div>
@endunless

<div id="inbox">

    {{-- ---------------------------------------------------------- --}}
    {{-- Conversation list                                           --}}
    {{-- ---------------------------------------------------------- --}}
    <div id="convList">
        <div class="p-2 border-bottom">
            <div class="input-group input-group-sm mb-2">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                <input type="text" id="searchConv" class="form-control border-start-0" placeholder="Search name or number…">
                <button class="btn btn-primary" id="btnNewChat" title="New conversation"><i class="bi bi-plus-lg"></i></button>
            </div>
            {{-- Channel filter. Threads from all three channels share one
                 inbox, so this is how an agent narrows to one. --}}
            <div class="btn-group btn-group-sm w-100 mb-2" role="group" id="channelFilters">
                <input type="radio" class="btn-check" name="channelFilter" id="chAll" value="" checked>
                <label class="btn btn-outline-secondary" for="chAll">All</label>
                @foreach($channels as $key => $meta)
                    <input type="radio" class="btn-check" name="channelFilter" id="ch{{ $key }}" value="{{ $key }}">
                    <label class="btn btn-outline-secondary" for="ch{{ $key }}" title="{{ $meta['label'] }}">
                        <i class="bi {{ $meta['icon'] }}"></i>
                    </label>
                @endforeach
            </div>

            <div class="btn-group btn-group-sm w-100" role="group" id="convFilters">
                <input type="radio" class="btn-check" name="convFilter" id="fAll" value="all" checked>
                <label class="btn btn-outline-secondary" for="fAll">All</label>

                <input type="radio" class="btn-check" name="convFilter" id="fUnread" value="unread">
                <label class="btn btn-outline-secondary" for="fUnread">Unread <span class="badge bg-danger d-none" id="unreadBadge">0</span></label>

                <input type="radio" class="btn-check" name="convFilter" id="fMine" value="mine">
                <label class="btn btn-outline-secondary" for="fMine">Mine</label>

                <input type="radio" class="btn-check" name="convFilter" id="fClosed" value="closed">
                <label class="btn btn-outline-secondary" for="fClosed">Closed</label>
            </div>
        </div>

        <div class="conv-scroll" id="convScroll">
            <div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm"></div></div>
        </div>
    </div>

    {{-- ---------------------------------------------------------- --}}
    {{-- Thread                                                      --}}
    {{-- ---------------------------------------------------------- --}}
    <div id="threadPane">
        <div id="emptyState" class="d-flex flex-column align-items-center justify-content-center h-100 text-muted">
            <i class="bi bi-whatsapp" style="font-size:3.5rem;color:#25d366"></i>
            <p class="mt-3 mb-1 fw-semibold">Select a conversation</p>
            <p class="small">Or start a new one with the + button.</p>
        </div>

        <div id="threadWrap" class="d-none flex-column h-100">
            {{-- Thread header --}}
            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom">
                <button class="btn btn-sm btn-link d-md-none px-1" id="backToList"><i class="bi bi-arrow-left"></i></button>
                <div class="avatar" id="thAvatar">–</div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-truncate" id="thName">—</div>
                    <div class="small text-muted text-truncate" id="thSub">—</div>
                </div>

                <select class="form-select form-select-sm w-auto" id="thAssign" title="Assign conversation">
                    <option value="">Unassigned</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                    @endforeach
                </select>

                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item th-status" href="#" data-status="open"><i class="bi bi-inbox me-2"></i>Mark open</a></li>
                        <li><a class="dropdown-item th-status" href="#" data-status="pending"><i class="bi bi-hourglass-split me-2"></i>Mark pending</a></li>
                        <li><a class="dropdown-item th-status" href="#" data-status="closed"><i class="bi bi-check2-circle me-2"></i>Close conversation</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="#" id="btnLinkLead"><i class="bi bi-link-45deg me-2"></i>Link to a lead</a></li>
                    </ul>
                </div>
            </div>

            {{-- Messages --}}
            <div id="messages" ondragover="event.preventDefault()"></div>

            {{-- 24h window notice --}}
            <div class="window-warning d-none" id="windowWarning">
                <i class="bi bi-clock-history"></i>
                <span class="flex-grow-1" id="windowWarningText">
                    The 24-hour reply window has closed. Send an approved template to reopen this conversation.
                </span>
                <button class="btn btn-sm btn-warning" id="btnOpenTemplate">Send template</button>
            </div>

            {{-- Still deliverable, but only as a tagged human-agent follow-up. --}}
            <div class="tag-notice d-none" id="tagNotice">
                <i class="bi bi-info-circle"></i>
                <span>Over 24 hours since they wrote — this will be sent as a human-agent follow-up.</span>
            </div>

            {{-- Composer --}}
            <div id="composer">
                <div id="dropZone">
                    <div class="attach-preview d-none" id="attachPreview">
                        <i class="bi bi-paperclip"></i>
                        <span class="flex-grow-1 text-truncate" id="attachName"></span>
                        <button class="btn btn-sm btn-link text-danger p-0" id="attachClear"><i class="bi bi-x-lg"></i></button>
                    </div>

                    <div class="d-flex align-items-end gap-2">
                        <input type="file" id="fileInput" class="d-none"
                               accept="image/jpeg,image/png,image/webp,video/mp4,audio/mpeg,audio/ogg,application/pdf,.doc,.docx,.xls,.xlsx,.txt">

                        <button class="btn btn-sm btn-light border" id="btnAttach" title="Attach a file">
                            <i class="bi bi-paperclip"></i>
                        </button>
                        <button class="btn btn-sm btn-light border" id="btnTemplate" title="Send an approved template">
                            <i class="bi bi-file-earmark-text"></i>
                        </button>
                        <button class="btn btn-sm btn-light border" id="btnNote" title="Add an internal note (not sent)">
                            <i class="bi bi-sticky"></i>
                        </button>

                        <textarea id="msgInput" class="form-control" rows="1" placeholder="Type a message…"></textarea>

                        <button class="btn btn-primary rounded-circle" id="btnSend" style="width:38px;height:38px">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </div>
                    <div class="form-text mt-1" id="composerHint">Enter to send · Shift+Enter for a new line</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------- --}}
    {{-- Context pane                                                --}}
    {{-- ---------------------------------------------------------- --}}
    <div id="contextPane" class="p-3">
        <div id="contextEmpty" class="text-muted small text-center py-5">
            <i class="bi bi-person-vcard d-block mb-2" style="font-size:1.8rem"></i>
            Contact details appear here.
        </div>
        <div id="contextBody" class="d-none"></div>
    </div>
</div>

{{-- ================= Modals ================= --}}

<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Send a template</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
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
                        <select class="form-select" id="templateSelect">
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
                    <div class="mb-3 d-none" id="templateHeaderWrap">
                        <label class="form-label">Header media URL</label>
                        <input type="url" class="form-control" id="templateHeaderLink" placeholder="https://…">
                        <div class="form-text">Must be a publicly reachable HTTPS link.</div>
                    </div>
                    <div id="templateVars"></div>
                    <div class="border rounded p-3 bg-light">
                        <div class="small text-muted mb-1">Preview</div>
                        <div id="templatePreview" class="small">—</div>
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="btnSendTemplate" @disabled($templates->isEmpty())>
                    <i class="bi bi-send me-1"></i>Send
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="newChatModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Start a conversation</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Mobile number</label>
                <input type="tel" class="form-control" id="newChatMobile" placeholder="e.g. 9812345678">
                <div class="form-text">10-digit numbers are prefixed with +91 automatically.</div>

                <div class="alert alert-light border small mt-3 mb-0">
                    <i class="bi bi-info-circle"></i>
                    New threads can only be opened on WhatsApp. Meta does not allow a business to
                    message someone first on Messenger or Instagram — those threads appear here
                    once the person writes to you.
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="btnStartChat">Open chat</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="noteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Internal note</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <textarea class="form-control" id="noteBody" rows="4" placeholder="Visible to your team only — never sent to the contact."></textarea>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-warning" id="btnSaveNote">Save note</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="mediaModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-dark">
            <div class="modal-body text-center p-2">
                <img id="mediaModalImg" src="" class="img-fluid rounded" alt="">
            </div>
            <div class="modal-footer border-0 py-2">
                <a href="#" id="mediaDownload" class="btn btn-sm btn-light" download><i class="bi bi-download me-1"></i>Download</a>
                <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    const routes = {
        list:     @json(route('chat.conversations')),
        show:     id => `{{ url('chat/conversations') }}/${id}`,
        send:     id => `{{ url('chat/conversations') }}/${id}/messages`,
        media:    id => `{{ url('chat/conversations') }}/${id}/media`,
        template: id => `{{ url('chat/conversations') }}/${id}/template`,
        note:     id => `{{ url('chat/conversations') }}/${id}/note`,
        assign:   id => `{{ url('chat/conversations') }}/${id}/assign`,
        status:   id => `{{ url('chat/conversations') }}/${id}/status`,
        start:    @json(route('chat.start')),
        poll:     @json(route('chat.poll')),
    };

    let currentId    = null;
    let lastMessageId = 0;
    let pendingFile  = null;
    let filter       = 'all';
    let channel      = '';
    let search       = '';
    let pollTimer    = null;

    const $scroll   = $('#convScroll');
    const $messages = $('#messages');

    // ------------------------------------------------------------------
    // Conversation list
    // ------------------------------------------------------------------
    function loadConversations(keepSelection = true) {
        const params = { q: search };

        if (channel) params.channel = channel;

        if (filter === 'unread') params.unread = 1;
        if (filter === 'mine')   params.mine = 1;
        if (filter === 'closed') params.status = 'closed';
        else if (filter !== 'all') params.status = 'open';

        return $.get(routes.list, params).done(res => {
            if (!res.data.length) {
                $scroll.html('<div class="text-center text-muted py-5 small">No conversations found.</div>');
                return;
            }

            $scroll.html(res.data.map(renderConvItem).join(''));

            if (keepSelection && currentId) {
                $(`.conv-item[data-id="${currentId}"]`).addClass('active');
            }

            const unread = res.data.filter(c => c.unread > 0).length;
            $('#unreadBadge').toggleClass('d-none', unread === 0).text(unread);
        });
    }

    function renderConvItem(c) {
        const unreadBadge = c.unread > 0
            ? `<span class="badge bg-success rounded-pill">${c.unread}</span>` : '';
        const windowDot = c.window_open
            ? '<i class="bi bi-circle-fill text-success" style="font-size:.5rem" title="Reply window open"></i>'
            : '<i class="bi bi-circle-fill text-secondary" style="font-size:.5rem" title="Reply window closed"></i>';

        // A real profile picture where the platform gives one; initials otherwise.
        const avatar = c.avatar
            ? `<img src="${c.avatar}" class="avatar" alt="" referrerpolicy="no-referrer">`
            : `<div class="avatar">${esc(c.initials)}</div>`;

        return `
            <div class="conv-item" data-id="${c.id}">
                <div class="avatar-wrap">
                    ${avatar}
                    <span class="channel-dot" style="background:${c.channel_color}"
                          title="${esc(c.channel_label)}"></span>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold text-truncate">${esc(c.name)}</span>
                        <small class="text-muted flex-shrink-0 ms-1">${esc(c.last_at || '')}</small>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted text-truncate">${esc(c.preview)}</small>
                        <span class="d-flex align-items-center gap-1 ms-1 flex-shrink-0">${windowDot}${unreadBadge}</span>
                    </div>
                </div>
            </div>`;
    }

    // ------------------------------------------------------------------
    // Thread
    // ------------------------------------------------------------------
    function openConversation(id) {
        currentId = id;
        lastMessageId = 0;

        $('.conv-item').removeClass('active');
        $(`.conv-item[data-id="${id}"]`).addClass('active');
        $('#emptyState').addClass('d-none');
        $('#threadWrap').removeClass('d-none').addClass('d-flex');
        $messages.html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm text-secondary"></div></div>');

        if (window.innerWidth < 768) $('#convList').addClass('hide-mobile');

        $.get(routes.show(id)).done(res => {
            renderHeader(res.conversation);
            renderContext(res.conversation);
            $messages.empty();
            renderMessages(res.messages, true);
            // Clearing the badge locally avoids a full list refetch.
            $(`.conv-item[data-id="${id}"] .badge.bg-success`).remove();
        });
    }

    function renderHeader(c) {
        $('#thAvatar').text(c.initials);
        $('#thName').text(c.name);

        // A phone number belongs on WhatsApp; a PSID is noise, so social
        // threads show the handle or just the channel instead.
        const who = c.is_social
            ? (c.username ? '@' + c.username : c.channel_label)
            : '+' + c.identifier;

        $('#thSub').html(
            `<i class="bi ${c.channel_icon}" style="color:${c.channel_color}"></i> ` + esc(who)
            + (c.window_open ? ` · ${esc(c.window_left)} left to reply` : ' · reply window closed')
        );

        $('#thAssign').val(c.assigned_to || '');

        const closed = !c.window_open;

        $('#windowWarning').toggleClass('d-none', !closed);
        $('#windowWarningText').text(c.is_social
            ? `${c.channel_label} no longer allows a reply on this thread — more than 7 days have passed since they wrote.`
            : 'The 24-hour reply window has closed. Send an approved template to reopen this conversation.');
        // Only WhatsApp can reopen a closed thread with a template.
        $('#btnOpenTemplate').toggleClass('d-none', !!c.is_social);

        // Between 24h and 7 days Meta still delivers, but only as a tagged
        // human-agent follow-up. Say so rather than letting it look normal.
        $('#tagNotice').toggleClass('d-none', !c.needs_tag);

        // Templates are a WhatsApp-only concept.
        $('#btnTemplate').prop('disabled', !c.can_template)
            .attr('title', c.can_template ? 'Send an approved template' : 'Templates are WhatsApp-only');

        $('#msgInput').prop('disabled', closed)
            .attr('placeholder', closed
                ? 'Reply window closed'
                : `Message on ${c.channel_label}…`);
        $('#btnSend, #btnAttach').prop('disabled', closed);
    }

    function renderContext(c) {
        if (!c.lead && !c.patient) {
            $('#contextEmpty').removeClass('d-none');
            $('#contextBody').addClass('d-none');
            return;
        }

        let html = '';

        if (c.lead) {
            html += `
                <div class="mb-3">
                    <div class="text-uppercase text-muted small fw-bold mb-2">Lead</div>
                    <div class="border rounded p-2">
                        <div class="fw-semibold">${esc(c.lead.name || 'Unnamed')}</div>
                        <div class="small text-muted">${esc(c.lead.mobile || '')}</div>
                        <hr class="my-2">
                        <div class="small"><span class="text-muted">Stage:</span> ${esc(c.lead.stage || '—')}</div>
                        <div class="small"><span class="text-muted">Status:</span> ${esc(c.lead.status || '—')}</div>
                        <div class="small"><span class="text-muted">Owner:</span> ${esc(c.lead.owner || 'Unassigned')}</div>
                        <a href="${c.lead.url}" class="btn btn-sm btn-outline-primary w-100 mt-2">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Open lead
                        </a>
                    </div>
                </div>`;
        }

        if (c.patient) {
            html += `
                <div class="mb-3">
                    <div class="text-uppercase text-muted small fw-bold mb-2">Patient</div>
                    <div class="border rounded p-2">
                        <div class="fw-semibold">${esc(c.patient.name)}</div>
                        <div class="small text-muted">${esc(c.patient.code)}</div>
                        <a href="${c.patient.url}" class="btn btn-sm btn-outline-success w-100 mt-2">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Open patient
                        </a>
                    </div>
                </div>`;
        }

        $('#contextEmpty').addClass('d-none');
        $('#contextBody').removeClass('d-none').html(html);
    }

    function renderMessages(messages, scrollToEnd) {
        let lastDate = $messages.find('.day-divider').last().data('date') || null;

        messages.forEach(m => {
            if (m.date !== lastDate) {
                $messages.append(`<div class="day-divider" data-date="${m.date}"><span>${m.date}</span></div>`);
                lastDate = m.date;
            }
            $messages.append(renderBubble(m));
            lastMessageId = Math.max(lastMessageId, m.id);
        });

        if (scrollToEnd) scrollBottom();
    }

    function renderBubble(m) {
        const out = m.direction === 'outbound';
        const classes = ['bubble', m.is_note ? 'note' : '', m.status === 'failed' ? 'failed' : ''].filter(Boolean).join(' ');

        let media = '';
        (m.attachments || []).forEach(a => {
            if (a.pending) {
                media += `<div class="small text-muted mb-1"><span class="spinner-border spinner-border-sm me-1"></span>Downloading media…</div>`;
            } else if (a.failed || !a.url) {
                media += `<div class="small text-danger mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Media unavailable</div>`;
            } else if (a.is_image) {
                media += `<img src="${a.url}" class="media" data-full="${a.url}" alt="${esc(a.filename || 'image')}">`;
            } else if (a.is_video) {
                media += `<video src="${a.url}" class="media" controls></video>`;
            } else if (a.is_audio) {
                media += `<audio src="${a.url}" controls class="w-100 mb-1"></audio>`;
            } else {
                media += `<a href="${a.url}?download=1" class="d-flex align-items-center gap-2 text-decoration-none border rounded p-2 mb-1 bg-white">
                            <i class="bi ${a.icon} fs-4 text-primary"></i>
                            <span class="small"><span class="d-block text-dark">${esc(a.filename || 'File')}</span>
                            <span class="text-muted">${esc(a.size || '')}</span></span>
                          </a>`;
            }
        });

        const tick = out && !m.is_note
            ? `<i class="bi ${m.status_icon} ${m.status_class}"></i>` : '';

        const label = m.is_note
            ? `<div class="small fw-bold text-warning-emphasis mb-1"><i class="bi bi-sticky me-1"></i>Internal note${m.agent ? ' · ' + esc(m.agent) : ''}</div>`
            : (m.template ? `<div class="small text-muted mb-1"><i class="bi bi-file-earmark-text me-1"></i>${esc(m.template)}</div>` : '');

        const error = m.status === 'failed' && m.error
            ? `<div class="small text-danger mt-1"><i class="bi bi-exclamation-circle me-1"></i>${esc(m.error)}</div>` : '';

        return `
            <div class="msg-row ${out ? 'out' : 'in'}" data-id="${m.id}">
                <div class="${classes}">
                    ${label}${media}
                    ${m.body ? `<div>${linkify(esc(m.body))}</div>` : ''}
                    ${error}
                    <div class="meta">${m.agent && !m.is_note && out ? esc(m.agent) + ' · ' : ''}${m.time} ${tick}</div>
                </div>
            </div>`;
    }

    function scrollBottom() {
        $messages.scrollTop($messages[0].scrollHeight);
    }

    // ------------------------------------------------------------------
    // Sending
    // ------------------------------------------------------------------
    function sendMessage() {
        if (!currentId) return;

        if (pendingFile) return sendFile();

        const body = $('#msgInput').val().trim();
        if (!body) return;

        $('#btnSend').prop('disabled', true);

        $.post(routes.send(currentId), { body })
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                $('#msgInput').val('').css('height', 'auto');
                renderMessages([res.message], true);
                loadConversations();
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not send the message.'))
            .always(() => $('#btnSend').prop('disabled', false));
    }

    function sendFile() {
        const data = new FormData();
        data.append('file', pendingFile);
        data.append('caption', $('#msgInput').val().trim());

        $('#btnSend').prop('disabled', true);
        toastr.info('Uploading…');

        $.ajax({
            url: routes.media(currentId),
            method: 'POST',
            data,
            processData: false,
            contentType: false,
        })
        .done(res => {
            if (!res.success) return toastr.error(res.message);
            clearAttachment();
            $('#msgInput').val('');
            renderMessages([res.message], true);
            loadConversations();
        })
        .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Upload failed.'))
        .always(() => $('#btnSend').prop('disabled', false));
    }

    function clearAttachment() {
        pendingFile = null;
        $('#fileInput').val('');
        $('#attachPreview').addClass('d-none');
    }

    // ------------------------------------------------------------------
    // Polling for inbound messages
    // ------------------------------------------------------------------
    function startPolling() {
        stopPolling();
        pollTimer = setInterval(() => {
            if (document.hidden) return;

            if (currentId) {
                $.get(routes.show(currentId), { after: lastMessageId }).done(res => {
                    if (res.messages.length) {
                        renderMessages(res.messages, true);
                        renderHeader(res.conversation);
                    }
                });
            }
            loadConversations();
        }, 10000);
    }

    function stopPolling() {
        if (pollTimer) clearInterval(pollTimer);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    function esc(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }

    function linkify(html) {
        return html.replace(/(https?:\/\/[^\s<]+)/g,
            '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>');
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------
    $scroll.on('click', '.conv-item', function () {
        openConversation($(this).data('id'));
    });

    $('#convFilters input').on('change', function () {
        filter = $(this).val();
        loadConversations();
    });

    $('#channelFilters input').on('change', function () {
        channel = $(this).val();
        loadConversations();
    });

    let searchTimer;
    $('#searchConv').on('input', function () {
        clearTimeout(searchTimer);
        search = $(this).val();
        searchTimer = setTimeout(loadConversations, 350);
    });

    $('#btnSend').on('click', sendMessage);

    $('#msgInput').on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    }).on('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    $('#btnAttach').on('click', () => $('#fileInput').click());
    $('#attachClear').on('click', clearAttachment);

    $('#fileInput').on('change', function () {
        if (!this.files.length) return;
        pendingFile = this.files[0];
        $('#attachName').text(pendingFile.name);
        $('#attachPreview').removeClass('d-none');
        $('#msgInput').attr('placeholder', 'Add a caption (optional)…').focus();
    });

    // Drag & drop onto the composer.
    const dropZone = document.getElementById('dropZone');
    ['dragenter', 'dragover'].forEach(evt =>
        dropZone.addEventListener(evt, e => { e.preventDefault(); dropZone.classList.add('dragging'); }));
    ['dragleave', 'drop'].forEach(evt =>
        dropZone.addEventListener(evt, e => { e.preventDefault(); dropZone.classList.remove('dragging'); }));
    dropZone.addEventListener('drop', e => {
        if (!e.dataTransfer.files.length || !currentId) return;
        pendingFile = e.dataTransfer.files[0];
        $('#attachName').text(pendingFile.name);
        $('#attachPreview').removeClass('d-none');
    });

    $messages.on('click', 'img.media', function () {
        const url = $(this).data('full');
        $('#mediaModalImg').attr('src', url);
        $('#mediaDownload').attr('href', url + '?download=1');
        new bootstrap.Modal('#mediaModal').show();
    });

    $('#thAssign').on('change', function () {
        if (!currentId) return;
        $.post(routes.assign(currentId), { assigned_to: $(this).val() || '' })
            .done(() => toastr.success('Conversation reassigned.'));
    });

    $(document).on('click', '.th-status', function (e) {
        e.preventDefault();
        if (!currentId) return;
        const status = $(this).data('status');
        $.post(routes.status(currentId), { status }).done(() => {
            toastr.success('Conversation marked ' + status + '.');
            loadConversations();
        });
    });

    $('#btnNewChat').on('click', () => new bootstrap.Modal('#newChatModal').show());

    $('#btnStartChat').on('click', function () {
        const mobile = $('#newChatMobile').val().trim();
        if (!mobile) return toastr.warning('Enter a mobile number.');

        $.post(routes.start, { mobile }).done(res => {
            if (!res.success) return toastr.error(res.message);
            bootstrap.Modal.getInstance(document.getElementById('newChatModal')).hide();
            $('#newChatMobile').val('');
            loadConversations(false).done(() => openConversation(res.conversation_id));
        }).fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not start the conversation.'));
    });

    $('#btnNote').on('click', () => {
        if (!currentId) return toastr.warning('Open a conversation first.');
        new bootstrap.Modal('#noteModal').show();
    });

    $('#btnSaveNote').on('click', function () {
        const body = $('#noteBody').val().trim();
        if (!body) return;

        $.post(routes.note(currentId), { body }).done(res => {
            bootstrap.Modal.getInstance(document.getElementById('noteModal')).hide();
            $('#noteBody').val('');
            renderMessages([res.message], true);
        });
    });

    // --- Templates ---
    $('#btnTemplate, #btnOpenTemplate').on('click', () => {
        if (!currentId) return toastr.warning('Open a conversation first.');
        new bootstrap.Modal('#templateModal').show();
    });

    $('#templateSelect').on('change', function () {
        const option = this.selectedOptions[0];
        const vars   = parseInt(option?.dataset.vars || 0, 10);
        const header = option?.dataset.header || '';

        $('#templateHeaderWrap').toggleClass('d-none', !['IMAGE', 'VIDEO', 'DOCUMENT'].includes(header));

        let html = '';
        for (let i = 1; i <= vars; i++) {
            html += `
                <div class="mb-2">
                    <label class="form-label small">Variable @{{${i}}}</label>
                    <input type="text" class="form-control form-control-sm tpl-var" data-index="${i}" placeholder="Value for @{{${i}}}">
                </div>`;
        }
        $('#templateVars').html(html);
        updateTemplatePreview();
    });

    $(document).on('input', '.tpl-var', updateTemplatePreview);

    function updateTemplatePreview() {
        const option = document.getElementById('templateSelect').selectedOptions[0];
        if (!option || !option.value) return $('#templatePreview').text('—');

        let body = option.dataset.body || '';
        $('.tpl-var').each(function () {
            const token = '@{{' + $(this).data('index') + '@}}';
            body = body.replaceAll(token, $(this).val() || '…');
        });
        $('#templatePreview').html(linkify(esc(body)));
    }

    $('#btnSendTemplate').on('click', function () {
        const templateId = $('#templateSelect').val();
        if (!templateId) return toastr.warning('Choose a template.');

        const params = {};
        $('.tpl-var').each(function () { params[$(this).data('index')] = $(this).val(); });

        $(this).prop('disabled', true);

        $.post(routes.template(currentId), {
            template_id: templateId,
            params,
            header_link: $('#templateHeaderLink').val() || null,
        })
        .done(res => {
            if (!res.success) return toastr.error(res.message);
            bootstrap.Modal.getInstance(document.getElementById('templateModal')).hide();
            renderMessages([res.message], true);
            // A template reopens the window, so refresh the header state.
            $.get(routes.show(currentId)).done(r => renderHeader(r.conversation));
            loadConversations();
        })
        .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not send the template.'))
        .always(() => $(this).prop('disabled', false));
    });

    $('#backToList').on('click', () => $('#convList').removeClass('hide-mobile'));

    $(window).on('beforeunload', stopPolling);

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------
    loadConversations(false).done(() => {
        const preselect = @json($selectedId);
        if (preselect) openConversation(preselect);
    });

    startPolling();
})();
</script>
@endpush
