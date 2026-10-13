{{--
    Send a flow to one contact.

    Shared by the flow list and the flow detail page. `.js-send` on any button
    (carrying data-id and data-name) opens it.
--}}
<div class="modal fade" id="sendFlowModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="sendFlowForm">
                <div class="modal-header">
                    <h5 class="modal-title">Send <span id="sendFlowName">flow</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="sendFlowId">

                    <div class="mb-3">
                        <label class="form-label">Mobile number <span class="text-danger">*</span></label>
                        <input type="tel" name="mobile" class="form-control" required placeholder="10-digit mobile"
                               data-mobile10 inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}"
                               title="10-digit Indian mobile number, without +91 or 0">
                        <div class="form-text">
                            The contact must have messaged you in the last 24 hours — a flow is an
                            interactive message, so it obeys the same window.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Message shown above the button</label>
                        <textarea name="body" class="form-control" rows="2"
                                  placeholder="Tell us a little about your case so we can help properly."></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-7">
                            <label class="form-label">Button label</label>
                            <input type="text" name="cta" class="form-control" maxlength="20" placeholder="Start">
                        </div>
                        <div class="col-5">
                            <label class="form-label">Footer</label>
                            <input type="text" name="footer" class="form-control" maxlength="60">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i>Send flow</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    $(document).on('click', '.js-send', function () {
        $('#sendFlowForm')[0].reset();
        $('#sendFlowId').val($(this).data('id'));
        $('#sendFlowName').text($(this).data('name') || 'flow');
        new bootstrap.Modal('#sendFlowModal').show();
    });

    $('#sendFlowForm').on('submit', function (e) {
        e.preventDefault();

        const id = $('#sendFlowId').val();
        const $btn = $(this).find('[type=submit]').prop('disabled', true);

        $.post(`{{ url('marketing/flows') }}/${id}/send`, $(this).serialize())
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                toastr.success(res.message);
                bootstrap.Modal.getInstance(document.getElementById('sendFlowModal')).hide();
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not send the flow.'))
            .always(() => $btn.prop('disabled', false));
    });
});
</script>
@endpush
