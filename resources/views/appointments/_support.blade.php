{{--
    The dialogs and handlers behind the appointment row actions (mark done,
    cancel with a reason, reschedule). Included once per page that renders
    appointments._actions. After a change the page calls apptAfterUpdate():
    the appointments list reloads its table, any other page reloads itself.
--}}
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Cancel Appointment</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-semibold" for="cancelReason">Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" id="cancelReason" rows="3" maxlength="1000" placeholder="Why is this appointment being cancelled?"></textarea>
                <div class="invalid-feedback">Give a reason (at least 3 characters).</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Keep</button>
                <button type="button" class="btn btn-danger btn-sm" id="confirmCancel">Cancel Appointment</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="rescheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Reschedule Appointment</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-semibold" for="rescheduleAt">New date &amp; time <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="rescheduleAt" placeholder="Pick date and time">
                <div class="invalid-feedback">Pick the new date and time.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Keep</button>
                <button type="button" class="btn btn-primary btn-sm" id="confirmReschedule">Reschedule</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function apptAfterUpdate() {
    if (window.appointmentTable) window.appointmentTable.ajax.reload(null, false);
    else window.location.reload();
}

function apptUpdate(url, data) {
    return $.ajax({ url, method: 'PUT', data })
        .done(() => { toastr.success('Appointment updated.'); apptAfterUpdate(); })
        .fail(x => toastr.error(
            x.responseJSON?.errors?.cancel_reason?.[0]
            || x.responseJSON?.errors?.scheduled_at?.[0]
            || x.responseJSON?.message
            || 'Could not update the appointment.'));
}

$(document).on('click', '.js-appt-done', function () {
    if (confirm('Mark this appointment as done (patient visited)?')) apptUpdate($(this).data('url'), { status: 'visited' });
});

let apptCancelUrl = null;
$(document).on('click', '.js-appt-cancel', function () {
    apptCancelUrl = $(this).data('url');
    $('#cancelReason').val('').removeClass('is-invalid');
    bootstrap.Modal.getOrCreateInstance($('#cancelModal')[0]).show();
});
$('#confirmCancel').on('click', function () {
    const reason = $('#cancelReason').val().trim();
    if (reason.length < 3) { $('#cancelReason').addClass('is-invalid'); return; }
    apptUpdate(apptCancelUrl, { status: 'cancelled', cancel_reason: reason })
        .done(() => bootstrap.Modal.getInstance($('#cancelModal')[0]).hide());
});

const reschedulePicker = flatpickr('#rescheduleAt', { enableTime: true, altInput: true, altFormat: 'd-m-Y h:i K', dateFormat: 'Y-m-d H:i', minDate: 'today', minuteIncrement: 15 });
let apptRescheduleUrl = null;
$(document).on('click', '.js-appt-reschedule', function () {
    apptRescheduleUrl = $(this).data('url');
    reschedulePicker.setDate($(this).data('when') || null, false);
    $('#rescheduleAt').removeClass('is-invalid');
    bootstrap.Modal.getOrCreateInstance($('#rescheduleModal')[0]).show();
});
$('#confirmReschedule').on('click', function () {
    const when = $('#rescheduleAt').val();
    if (!when) { $('#rescheduleAt').addClass('is-invalid'); return; }
    apptUpdate(apptRescheduleUrl, { scheduled_at: when, status: 'rescheduled' })
        .done(() => bootstrap.Modal.getInstance($('#rescheduleModal')[0]).hide());
});
</script>
@endpush
