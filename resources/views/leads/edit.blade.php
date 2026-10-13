@extends('layouts.app')

@section('title', 'Edit Lead')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leads.index') }}">Leads</a></li>
    <li class="breadcrumb-item"><a href="{{ route('leads.show', $lead) }}">{{ $lead->name ?? $lead->mobile }}</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-9">

<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-bottom">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-pencil me-2"></i>Edit Lead — {{ $lead->name ?? $lead->mobile }}</h6>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('leads.update', $lead) }}">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-12"><h6 class="text-primary fw-semibold border-bottom pb-1">Personal Details</h6></div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Full Name</label>
                    <input type="text" name="name" class="form-control" value="{{ old('name', $lead->name) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Mobile <span class="text-danger">*</span></label>
                    {{-- A readonly (masked) field is exempt from the pattern check by the browser, so telecallers can still save. --}}
                    @php $canEditNumbers = \App\Support\PhoneNumber::canView(); @endphp
                    <input type="tel" name="mobile" class="form-control @error('mobile') is-invalid @enderror" value="{{ old('mobile', \App\Support\PhoneNumber::display($lead->mobile)) }}" required
                           @if($canEditNumbers) data-mobile10 inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" title="10-digit Indian mobile number, without +91 or 0"
                           @else readonly title="Number hidden" @endif>
                    @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Alt Mobile</label>
                    <input type="tel" name="alt_mobile" class="form-control @error('alt_mobile') is-invalid @enderror" value="{{ old('alt_mobile', \App\Support\PhoneNumber::display($lead->alt_mobile)) }}"
                           @if($canEditNumbers) data-mobile10 inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" title="10-digit Indian mobile number, without +91 or 0"
                           @else readonly title="Number hidden" @endif>
                    @error('alt_mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control" value="{{ old('email', $lead->email) }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">Select</option>
                        @foreach(['female','male','other'] as $g)
                            <option value="{{ $g }}" {{ old('gender', $lead->gender) === $g ? 'selected' : '' }}>{{ ucfirst($g) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Lead Date</label>
                    <input type="text" name="lead_date" class="form-control flatpickr"
                           value="{{ old('lead_date', $lead->lead_date?->format('Y-m-d H:i')) }}">
                </div>

                <!-- The couple: typed as years, stored as dates so they keep counting -->
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Wife's age</label>
                    <input type="number" name="wife_age" class="form-control" value="{{ old('wife_age', $lead->wife_age_years) }}" min="1" max="100" placeholder="yrs">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Husband's age</label>
                    <input type="number" name="husband_age" class="form-control" value="{{ old('husband_age', $lead->husband_age_years) }}" min="1" max="100" placeholder="yrs">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Married since</label>
                    <input type="number" name="years_married" class="form-control" value="{{ old('years_married', $lead->years_married) }}" min="0" max="80" placeholder="yrs">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Trying since</label>
                    <input type="number" name="years_trying" class="form-control" value="{{ old('years_trying', $lead->years_trying) }}" min="0" max="60" placeholder="yrs">
                </div>

                <div class="col-12"><h6 class="text-primary fw-semibold border-bottom pb-1 mt-2">Attribution & Assignment</h6></div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Lead Source</label>
                    <select name="lead_source_id" class="form-select">
                        <option value="">Select Source</option>
                        @foreach($sources as $s)
                            <option value="{{ $s->id }}" {{ old('lead_source_id', $lead->lead_source_id) == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Center</label>
                    <select name="center_id" class="form-select">
                        <option value="">Select Center</option>
                        @foreach($centers as $c)
                            <option value="{{ $c->id }}" {{ old('center_id', $lead->center_id) == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Assign To</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">Unassigned</option>
                        @foreach($telecallers as $u)
                            <option value="{{ $u->id }}" {{ old('assigned_to', $lead->assigned_to) == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold">Remark</label>
                    <textarea name="remark" class="form-control" rows="3">{{ old('remark', $lead->remark) }}</textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Update Lead
                </button>
                <a href="{{ route('leads.show', $lead) }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

</div>
</div>
@endsection

@push('scripts')
<script>
$('.flatpickr').flatpickr({ enableTime: true, altInput: true, altFormat: 'd-m-Y h:i K', dateFormat: 'Y-m-d H:i' });
</script>
@endpush
