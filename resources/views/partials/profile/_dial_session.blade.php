{{--
    Bulk-calling banner + auto-dial.

    Shown on a lead's profile while the agent is in a bulk-calling run and
    this lead is the one under the cursor. With ?autodial=1 in the URL the
    page places the click-to-call itself, so the agent only ever answers
    their phone and saves the outcome.

    Expects $lead.
--}}
@php
    $dialSession = auth()->check() && isset($lead) ? \App\Models\DialSession::activeFor(auth()->user()) : null;
    $isCurrent   = $dialSession?->isCurrent($lead) ?? false;
    $autodial    = $isCurrent && request()->boolean('autodial') && auth()->user()->can('call_logs.create');
@endphp

@if($dialSession && $isCurrent)
    <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-3" id="dialSessionBar" data-autodial="{{ $autodial ? 1 : 0 }}" data-lead="{{ $lead->id }}">
        <i class="bi bi-telephone-outbound-fill"></i>
        <div class="flex-grow-1 small">
            <strong>Bulk calling</strong> · lead {{ $dialSession->position() }} of {{ $dialSession->total() }}
            <span class="text-muted" id="dialSessionHint">
                {{ $autodial ? '— dialling now, answer your phone…' : '— press Call to dial this lead, then save the outcome to move on.' }}
            </span>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="dialSessionSkip" title="Move on without calling this lead">
            <i class="bi bi-skip-forward me-1"></i>Skip
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger" id="dialSessionStop" title="End the bulk-calling run">
            <i class="bi bi-stop-circle me-1"></i>Stop
        </button>
    </div>

    @push('scripts')
    <script>
    $(function () {
        const $bar = $('#dialSessionBar');
        const routes = {
            skip: @json(route('leads.bulk_call.skip')),
            stop: @json(route('leads.bulk_call.stop')),
        };

        $('#dialSessionSkip').on('click', function () {
            $(this).prop('disabled', true);
            $.post(routes.skip).done(r => { toastr.info(r.message); window.location.href = r.redirect; })
                .fail(() => $(this).prop('disabled', false));
        });

        $('#dialSessionStop').on('click', function () {
            $(this).prop('disabled', true);
            $.post(routes.stop).done(r => { toastr.info(r.message); window.location.href = r.redirect; })
                .fail(() => $(this).prop('disabled', false));
        });

        if ($bar.data('autodial') !== 1) return;

        // Drop the flag from the address bar so a refresh does not dial again.
        try { history.replaceState(null, '', window.location.pathname); } catch (e) {}

        // The click-to-call handler is delegated from the global dialer, so
        // firing the lead's own Call button places the call and keeps every
        // other behaviour (live pane, disposition modal) identical.
        $(window).on('load', function () {
            setTimeout(function () {
                const $call = $('.js-click-to-call[data-lead="' + $bar.data('lead') + '"]').first();
                if ($call.length) {
                    $call.trigger('click');
                } else {
                    $('#dialSessionHint').text('— no Call button on this page; dial from your phone or skip.');
                }
            }, 400);
        });
    });
    </script>
    @endpush
@endif
