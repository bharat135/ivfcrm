{{--
    Log this call: disposition, sub-disposition, next follow-up and remark.

    This is the single action a telecaller opens a record to perform, so it sits
    at the top of the overview rather than behind a tab. Nothing is pre-selected:
    every save is a fresh outcome. Expects $lead, $dispositions and optionally
    $latestCall (the lead's most recent call log).
--}}
@php
    $latestCall = $latestCall ?? null;
@endphp
@can('leads.edit')
<div class="card border-0 shadow-sm mb-4 log-call">
    <div class="card-header bg-transparent py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h6 class="mb-0 fw-semibold">Log this call</h6>
            @if($latestCall)
                <span class="log-chip" title="{{ $latestCall->started_at?->format('d-m-Y h:i A') }}">
                    Last call <strong>{{ ucfirst(str_replace(['-', '_'], ' ', $latestCall->status)) }} · {{ $latestCall->started_at?->isToday() ? $latestCall->started_at->format('h:i A') . ' today' : $latestCall->started_at?->format('d-m-Y h:i A') }}</strong>
                </span>
            @endif
            @if($lead->next_follow_up_at)
                <span class="log-chip {{ $lead->next_follow_up_at->isPast() ? 'is-due' : '' }}" title="{{ $lead->next_follow_up_at->format('d-m-Y h:i A') }}">
                    <i class="bi bi-alarm"></i> Follow-up {{ $lead->next_follow_up_at->isPast() ? 'was due' : 'due' }} <strong>{{ $lead->next_follow_up_at->diffForHumans() }}</strong>
                </span>
            @else
                <span class="log-chip">No follow-up set</span>
            @endif
        </div>
        <a href="{{ route('leads.next', ['after' => $lead->id]) }}" class="btn btn-outline-secondary btn-sm" title="Open the next uncalled lead or due follow-up">
            Next lead <i class="bi bi-chevron-right"></i>
        </a>
    </div>
    <div class="card-body">
        <form id="dispositionForm">
            @csrf
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold" for="disposition">Disposition <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" id="disposition" name="disposition_id" required>
                        <option value="">Select</option>
                        @foreach($dispositions as $disposition)
                            <option value="{{ $disposition->id }}" data-requires-followup="{{ $disposition->requires_followup ? '1' : '0' }}">
                                {{ $disposition->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted" for="subDisposition">Sub-disposition <span class="fw-normal">· when the disposition has one</span></label>
                    <select class="form-select form-select-sm" id="subDisposition" name="sub_disposition_id" disabled>
                        <option value="">Select</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold" for="nextFollowUp">Next follow-up <span class="fw-normal text-muted">date &amp; time</span> <span class="text-danger d-none" id="followUpRequired">*</span></label>
                    <input type="text" name="next_follow_up_at" id="nextFollowUp" class="form-control form-control-sm" placeholder="Pick date and time">
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        <button type="button" class="btn btn-xs btn-outline-primary rounded-pill js-quick-followup" data-pick="1h">In 1 hour</button>
                        <button type="button" class="btn btn-xs btn-outline-primary rounded-pill js-quick-followup" data-pick="eve">Today 5 PM</button>
                        <button type="button" class="btn btn-xs btn-outline-primary rounded-pill js-quick-followup" data-pick="tmrw">Tomorrow 11 AM</button>
                        <button type="button" class="btn btn-xs btn-outline-primary rounded-pill js-quick-followup" data-pick="3d">In 3 days</button>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold" for="remark">Remark <span class="text-danger">*</span></label>
                    <textarea name="remark" id="remark" class="form-control form-control-sm" rows="3" required
                              placeholder="What was said on the call?"></textarea>
                </div>
            </div>
        </form>
    </div>
    <div class="card-footer bg-light d-flex justify-content-end gap-2 py-2">
        <button type="submit" form="dispositionForm" class="btn btn-outline-secondary btn-sm" data-stay="1">Save and stay</button>
        <button type="submit" form="dispositionForm" class="btn btn-primary btn-sm">
            Save &amp; open next lead <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</div>

@push('scripts')
<script>
// Sub-dispositions are children of the selected disposition, so they are held
// on the client rather than fetched on every change.
const subDispositions = @json($dispositions->mapWithKeys(fn ($d) => [
    $d->id => $d->children->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
]));

const followUpPicker = flatpickr('#nextFollowUp', {
    enableTime: true, dateFormat: 'Y-m-d H:i', altInput: true, altFormat: 'd-m-Y h:i K',
    minDate: 'today', minuteIncrement: 15,
});

function refreshDisposition() {
    const option   = $('#disposition').find(':selected');
    const children = subDispositions[$('#disposition').val()] || [];

    $('#subDisposition')
        .empty()
        .append('<option value="">Select</option>')
        .append(children.map(c => `<option value="${c.id}">${c.name}</option>`).join(''))
        .prop('disabled', children.length === 0);

    // A disposition that promises a callback must capture when.
    const needsFollowUp = option.data('requires-followup') === 1 || option.data('requires-followup') === '1';
    $('#followUpRequired').toggleClass('d-none', !needsFollowUp);
    $('#nextFollowUp').prop('required', needsFollowUp);
}

$('#disposition').on('change', refreshDisposition);
refreshDisposition();

// One tap for the usual callback times; the picker stays for anything else.
$('.js-quick-followup').on('click', function () {
    const d = new Date();
    switch ($(this).data('pick')) {
        case '1h':   d.setHours(d.getHours() + 1); break;
        case 'eve':  d.setHours(17, 0, 0, 0); break;
        case 'tmrw': d.setDate(d.getDate() + 1); d.setHours(11, 0, 0, 0); break;
        case '3d':   d.setDate(d.getDate() + 3); d.setHours(11, 0, 0, 0); break;
    }
    followUpPicker.setDate(d, true);
});

$('#dispositionForm').on('submit', function (e) {
    e.preventDefault();
    const stay = e.originalEvent?.submitter?.dataset.stay === '1';
    const btns = $('[form=dispositionForm]').prop('disabled', true);

    $.post('{{ route("leads.disposition", $lead) }}', $(this).serialize())
        .done(res => {
            if (stay) {
                toastr.success('Disposition saved.');
                window.location.reload();
                return;
            }
            toastr.success('Disposition saved. Opening the next lead…');
            window.location.href = res?.next_url || '{{ route('leads.next', ['after' => $lead->id]) }}';
        })
        .fail(xhr => {
            const errors = xhr.responseJSON?.errors || {};
            toastr.error(Object.values(errors).flat().join('<br>') || 'Could not save this disposition.');
            btns.prop('disabled', false);
        });
});
</script>
@endpush
@endcan
