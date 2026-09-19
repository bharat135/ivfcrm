{{--
    Shared ad-set builder modal.

    Used by both the Ad Sets list and the Campaign detail page. When
    $lockedCampaignId is passed the campaign selector is fixed, because the
    user is already inside that campaign.
--}}
@php $lockedCampaignId = $lockedCampaignId ?? null; @endphp

<div class="modal fade" id="adsetModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="adsetForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="adsetModalTitle">New ad set</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" id="adsetId">

                    <ul class="nav nav-pills nav-fill mb-3">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#asBasics">1. Basics</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#asAudience">2. Audience</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#asSchedule">3. Budget &amp; schedule</a></li>
                    </ul>

                    <div class="tab-content">
                        {{-- Basics --}}
                        <div class="tab-pane fade show active" id="asBasics">
                            <div class="mb-3">
                                <label class="form-label">Campaign <span class="text-danger">*</span></label>
                                <select name="campaign_id" class="form-select" required @disabled($lockedCampaignId)>
                                    @foreach($campaigns as $campaign)
                                        <option value="{{ $campaign->id }}" @selected($lockedCampaignId === $campaign->id)>
                                            {{ $campaign->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @if($lockedCampaignId)
                                    <input type="hidden" name="campaign_id" value="{{ $lockedCampaignId }}">
                                @endif
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Ad set name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required placeholder="e.g. Women 25-40 · Delhi NCR">
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Optimisation goal <span class="text-danger">*</span></label>
                                    <select name="optimization_goal" class="form-select" required>
                                        @foreach($optimizationGoals as $value => $label)
                                            <option value="{{ $value }}" @selected($value === 'LEAD_GENERATION')>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Billing event</label>
                                    <select name="billing_event" class="form-select">
                                        @foreach($billingEvents as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="mt-3">
                                <label class="form-label">Where should people go?</label>
                                <select name="destination_type" class="form-select">
                                    @foreach($destinationTypes as $value => $label)
                                        <option value="{{ $value }}" @selected($value === 'ON_AD')>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">
                                    "Instant Form" keeps the user on Facebook; "WhatsApp" opens a chat that lands in your CRM inbox.
                                </div>
                            </div>
                        </div>

                        {{-- Audience --}}
                        <div class="tab-pane fade" id="asAudience">
                            <div class="row g-3 mb-3">
                                <div class="col-4">
                                    <label class="form-label">Min age</label>
                                    <input type="number" name="age_min" class="form-control" value="21" min="18" max="65">
                                </div>
                                <div class="col-4">
                                    <label class="form-label">Max age</label>
                                    <input type="number" name="age_max" class="form-control" value="45" min="18" max="65">
                                </div>
                                <div class="col-4">
                                    <label class="form-label">Gender</label>
                                    <select name="genders" class="form-select">
                                        <option value="0">All</option>
                                        <option value="2" selected>Women</option>
                                        <option value="1">Men</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Cities</label>
                                <select name="cities[]" class="form-select" id="citySelect" multiple></select>
                                <div class="form-text">Type at least two letters to search Meta's location database.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Interests</label>
                                <select name="interests[]" class="form-select" id="interestSelect" multiple></select>
                                <div class="form-text">
                                    e.g. "Fertility", "Pregnancy", "In vitro fertilisation". Leave empty for a broad audience.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label d-block">Placements</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="platforms[]" value="facebook" id="pfFb" checked>
                                    <label class="form-check-label" for="pfFb">Facebook</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="platforms[]" value="instagram" id="pfIg" checked>
                                    <label class="form-check-label" for="pfIg">Instagram</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="platforms[]" value="audience_network" id="pfAn">
                                    <label class="form-check-label" for="pfAn">Audience Network</label>
                                </div>
                            </div>

                            <div class="border rounded p-3 bg-light d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="small text-muted">Estimated audience size</div>
                                    <div class="h5 mb-0" id="reachEstimate">—</div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="btnEstimate">
                                    <i class="bi bi-people me-1"></i>Estimate reach
                                </button>
                            </div>
                        </div>

                        {{-- Budget --}}
                        <div class="tab-pane fade" id="asSchedule">
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Daily budget (₹)</label>
                                    <input type="number" name="daily_budget" class="form-control" min="50" step="10" value="500">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Lifetime budget (₹)</label>
                                    <input type="number" name="lifetime_budget" class="form-control" min="50" step="100">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Bid cap (₹, optional)</label>
                                <input type="number" name="bid_amount" class="form-control" min="1" step="1">
                                <div class="form-text">Only used with a bid-cap or cost-cap bid strategy.</div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Start</label>
                                    <input type="datetime-local" name="start_time" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">End</label>
                                    <input type="datetime-local" name="end_time" class="form-control">
                                </div>
                            </div>

                            <div class="alert alert-info small mt-3 mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                Ad sets are created <strong>paused</strong>. Review the ads inside them, then activate.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save ad set</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    // Select2 typeaheads backed by Meta's targeting search endpoints.
    $('#citySelect').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#adsetModal'),
        placeholder: 'Search cities…',
        minimumInputLength: 2,
        ajax: {
            url: @json(route('marketing.adsets.search_locations')),
            dataType: 'json',
            delay: 300,
            data: params => ({ q: params.term, type: 'city' }),
            processResults: data => ({
                results: (data.results || []).map(r => ({
                    id: r.key,
                    text: [r.name, r.region, r.country_name].filter(Boolean).join(', '),
                })),
            }),
        },
    });

    $('#interestSelect').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#adsetModal'),
        placeholder: 'Search interests…',
        minimumInputLength: 2,
        ajax: {
            url: @json(route('marketing.adsets.search_interests')),
            dataType: 'json',
            delay: 300,
            data: params => ({ q: params.term }),
            processResults: data => ({
                results: (data.results || []).map(r => ({
                    id: r.id,
                    text: r.name + (r.audience_size_upper_bound
                        ? ' (~' + Intl.NumberFormat('en', { notation: 'compact' }).format(r.audience_size_upper_bound) + ')'
                        : ''),
                })),
            }),
        },
    });

    function targetingPayload() {
        return {
            age_min:   $('[name=age_min]').val(),
            age_max:   $('[name=age_max]').val(),
            genders:   $('[name=genders]').val(),
            cities:    $('#citySelect').val() || [],
            interests: $('#interestSelect').val() || [],
            platforms: $('[name="platforms[]"]:checked').map((i, el) => el.value).get(),
            optimization_goal: $('[name=optimization_goal]').val(),
        };
    }

    $('#btnEstimate').on('click', function () {
        const $btn = $(this).prop('disabled', true);
        $('#reachEstimate').text('…');

        $.post(@json(route('marketing.adsets.reach')), targetingPayload())
            .done(res => {
                if (!res.success) { $('#reachEstimate').text('—'); return toastr.error(res.message); }

                const lower = res.estimate?.estimate_mau_lower_bound ?? res.estimate?.estimate_dau ?? 0;
                const upper = res.estimate?.estimate_mau_upper_bound ?? lower;
                const fmt = n => Intl.NumberFormat('en', { notation: 'compact' }).format(n);

                $('#reachEstimate').text(upper ? `${fmt(lower)} – ${fmt(upper)} people` : 'Not available');
            })
            .fail(xhr => { $('#reachEstimate').text('—'); toastr.error(xhr.responseJSON?.message || 'Estimate failed.'); })
            .always(() => $btn.prop('disabled', false));
    });

    $('#adsetForm').on('submit', function (e) {
        e.preventDefault();

        const id = $('#adsetId').val();
        const $btn = $(this).find('[type=submit]').prop('disabled', true);
        const data = $(this).serialize() + (id ? '&_method=PUT' : '');

        $.post(id ? `{{ url('marketing/adsets') }}/${id}` : @json(route('marketing.adsets.store')), data)
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                toastr.success(res.message);
                bootstrap.Modal.getInstance(document.getElementById('adsetModal')).hide();
                setTimeout(() => location.reload(), 800);
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not save the ad set.'))
            .always(() => $btn.prop('disabled', false));
    });
});
</script>
@endpush
