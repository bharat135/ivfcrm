{{--
    Log this call: disposition, sub-disposition, next follow-up and remark.

    This is the single action a telecaller opens a record to perform, so it sits
    at the top of the overview rather than behind a tab. Nothing is pre-selected:
    every save is a fresh outcome. A follow-up time is required for every
    disposition except the ones that stop calling (the lost ones).
    Expects $lead and $dispositions.
--}}
@can('leads.edit')
<div class="card shadow-none border mb-4 log-call">
    <div class="card-body">
        <form id="dispositionForm">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold" for="disposition">Disposition <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" id="disposition" name="disposition_id" required>
                        <option value="">Select</option>
                        @foreach($dispositions as $disposition)
                            <option value="{{ $disposition->id }}" data-stop-call="{{ $disposition->is_lost ? '1' : '0' }}">
                                {{ $disposition->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted" for="subDisposition" id="subDispositionLabel">Sub-disposition <span class="fw-normal">· when the disposition has one</span></label>
                    <select class="form-select form-select-sm" id="subDisposition" name="sub_disposition_id" disabled>
                        <option value="">Select</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold" for="nextFollowUp">Next follow-up <span class="fw-normal text-muted">date &amp; time</span> <span class="text-danger" id="followUpRequired">*</span></label>
                    <input type="text" name="next_follow_up_at" id="nextFollowUp" class="form-control form-control-sm" placeholder="Pick date and time">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold d-flex justify-content-between align-items-center" for="remark">
                        <span>Remark <span class="text-danger">*</span></span>
                        <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill" id="remarkMic" title="Speak the remark (speech to text)">
                            <i class="bi bi-mic"></i> <span class="d-none d-sm-inline">Speak</span>
                        </button>
                    </label>
                    <textarea name="remark" id="remark" class="form-control form-control-sm" rows="3" required
                              placeholder="What was said on the call?"></textarea>
                </div>
            </div>
        </form>
    </div>
    <div class="card-footer bg-light d-flex justify-content-between align-items-center gap-2 py-2 flex-wrap">
        <a href="{{ route('leads.next', ['after' => $lead->id]) }}" class="small text-decoration-none" title="Open the next uncalled lead or due follow-up without saving">
            Skip to next lead <i class="bi bi-chevron-right"></i>
        </a>
        <div class="d-flex gap-2">
            <button type="submit" form="dispositionForm" class="btn btn-outline-secondary btn-sm" data-stay="1">Save and stay</button>
            <button type="submit" form="dispositionForm" class="btn btn-primary btn-sm">
                Save &amp; open next lead <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </div>
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

    // Every outcome needs a callback time, except the ones that stop calling;
    // those take the reason instead.
    const stopsCalling = String(option.data('stop-call')) === '1';
    $('#followUpRequired').toggleClass('d-none', stopsCalling);
    $('#nextFollowUp').prop('required', !stopsCalling);

    const needsReason = stopsCalling && children.length > 0;
    $('#subDisposition').prop('required', needsReason);
    $('#subDispositionLabel')
        .toggleClass('text-muted', !needsReason)
        .html(needsReason
            ? 'Reason <span class="text-danger">*</span>'
            : 'Sub-disposition <span class="fw-normal">· when the disposition has one</span>');
}

$('#disposition').on('change', refreshDisposition);
refreshDisposition();

// Speech to text for the remark (browser speech recognition; Chrome and Edge).
(function () {
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const $mic = $('#remarkMic');
    if (!Recognition) { $mic.prop('disabled', true).attr('title', 'Speech to text is not supported by this browser'); return; }

    let recognizer = null;
    $mic.on('click', function () {
        if (recognizer) { recognizer.stop(); return; }
        recognizer = new Recognition();
        recognizer.lang = 'en-IN';
        recognizer.interimResults = false;
        recognizer.continuous = true;
        recognizer.onresult = e => {
            let text = '';
            for (let i = e.resultIndex; i < e.results.length; i++) if (e.results[i].isFinal) text += e.results[i][0].transcript;
            if (!text) return;
            const $r = $('#remark');
            $r.val(($r.val().trim() + ' ' + text.trim()).trim());
        };
        recognizer.onerror = e => { toastr.error(e.error === 'not-allowed' ? 'Allow microphone access to dictate the remark.' : 'Could not hear you. Try again.'); };
        recognizer.onend = () => { recognizer = null; $mic.removeClass('btn-danger').addClass('btn-outline-secondary').find('i').attr('class', 'bi bi-mic'); };
        recognizer.start();
        $mic.removeClass('btn-outline-secondary').addClass('btn-danger').find('i').attr('class', 'bi bi-stop-fill');
    });
})();

$('#dispositionForm').on('submit', function (e) {
    e.preventDefault();
    const stay = e.originalEvent?.submitter?.dataset.stay === '1';
    const btns = $('[form=dispositionForm]').prop('disabled', true);

    $.post('{{ route("leads.disposition", $lead) }}', $(this).serialize())
        .done(res => {
            if (stay) {
                toastr.success('Disposition saved.');
                setTimeout(() => window.location.reload(), 600);
                return;
            }
            toastr.success('Disposition saved. Opening the next lead…');
            const url = new URL(res?.next_url || '{{ route('leads.next', ['after' => $lead->id]) }}', window.location.origin);
            url.searchParams.set('saved', '1');
            setTimeout(() => { window.location.href = url.toString(); }, 700);
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
