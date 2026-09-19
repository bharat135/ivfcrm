{{--
    Log a call and set the disposition.

    This is the single action a telecaller opens a record to perform, so it sits
    at the top of the overview rather than behind a tab. Expects $lead and
    $dispositions.
--}}
@can('leads.edit')
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-2 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold small">Log a call</h6>
        @if($lead->next_follow_up_at)
            <span class="small {{ $lead->next_follow_up_at->isPast() ? 'text-danger fw-semibold' : 'text-muted' }}">
                <i class="bi bi-alarm me-1"></i>Next follow-up {{ $lead->next_follow_up_at->diffForHumans() }}
            </span>
        @endif
    </div>
    <div class="card-body">
        <form id="dispositionForm">
            @csrf
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Disposition</label>
                    <select class="form-select form-select-sm" id="disposition" name="disposition_id" required>
                        <option value="">Select</option>
                        @foreach($dispositions as $disposition)
                            <option value="{{ $disposition->id }}"
                                    data-requires-followup="{{ $disposition->requires_followup ? '1' : '0' }}"
                                    @selected($lead->disposition_id == $disposition->id)>
                                {{ $disposition->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 d-none" id="subDispositionWrap">
                    <label class="form-label small fw-semibold">Sub-disposition</label>
                    <select class="form-select form-select-sm" id="subDisposition" name="sub_disposition_id">
                        <option value="">Select</option>
                    </select>
                </div>
                <div class="col-md-4 d-none" id="followUpWrapper">
                    <label class="form-label small fw-semibold">Next follow-up <span class="text-danger">*</span></label>
                    <input type="text" name="next_follow_up_at" id="nextFollowUp"
                           class="form-control form-control-sm flatpickr-dt" placeholder="Date and time">
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Remark <span class="text-danger">*</span></label>
                    <textarea name="remark" class="form-control form-control-sm" rows="2" required
                              placeholder="What was said on the call?">{{ $lead->remark }}</textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-3">
                <i class="bi bi-check2 me-1"></i>Save disposition
            </button>
        </form>
    </div>
</div>

@push('scripts')
<script>
// Sub-dispositions are children of the selected disposition, so they are held
// on the client rather than fetched on every change.
const subDispositions = @json($dispositions->mapWithKeys(fn ($d) => [
    $d->id => $d->children->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
]));

function refreshDisposition() {
    const option   = $('#disposition').find(':selected');
    const children = subDispositions[$('#disposition').val()] || [];

    $('#subDisposition')
        .empty()
        .append('<option value="">Select</option>')
        .append(children.map(c => `<option value="${c.id}">${c.name}</option>`).join(''));
    $('#subDispositionWrap').toggleClass('d-none', children.length === 0);

    // A disposition that promises a callback must capture when.
    const needsFollowUp = option.data('requires-followup') === 1 || option.data('requires-followup') === '1';
    $('#followUpWrapper').toggleClass('d-none', !needsFollowUp);
    $('#nextFollowUp').prop('required', needsFollowUp);
}

$('#disposition').on('change', refreshDisposition);
refreshDisposition();

$('#dispositionForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type=submit]').prop('disabled', true);

    $.post('{{ route("leads.disposition", $lead) }}', $(this).serialize())
        .done(() => { toastr.success('Disposition saved.'); window.location.reload(); })
        .fail(xhr => {
            const errors = xhr.responseJSON?.errors || {};
            toastr.error(Object.values(errors).flat().join('<br>') || 'Could not save this disposition.');
            btn.prop('disabled', false);
        });
});
</script>
@endpush
@endcan
