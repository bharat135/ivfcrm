@extends('layouts.app')

@section('title', 'New Lead')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leads.index') }}">Leads</a></li>
    <li class="breadcrumb-item active">New Lead</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-9">

<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-bottom">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-person-plus me-2"></i>Create New Lead</h6>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('leads.store') }}">
            @csrf
            <div class="row g-3">
                <!-- Personal -->
                <div class="col-12"><h6 class="text-primary fw-semibold border-bottom pb-1">Personal Details</h6></div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Full Name</label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name') }}" placeholder="Patient / Lead Name">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Mobile <span class="text-danger">*</span></label>
                    <input type="text" name="mobile" class="form-control @error('mobile') is-invalid @enderror"
                           value="{{ old('mobile') }}" placeholder="+91 XXXXX XXXXX" required>
                    @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Alternate Mobile</label>
                    <input type="text" name="alt_mobile" class="form-control" value="{{ old('alt_mobile') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control" value="{{ old('email') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">Select</option>
                        <option value="female" {{ old('gender')=='female'?'selected':'' }}>Female</option>
                        <option value="male" {{ old('gender')=='male'?'selected':'' }}>Male</option>
                        <option value="other" {{ old('gender')=='other'?'selected':'' }}>Other</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Lead Date</label>
                    <input type="text" name="lead_date" class="form-control flatpickr" value="{{ old('lead_date', now()->format('Y-m-d H:i')) }}">
                </div>

                <!-- The couple: typed as years, stored as dates so they keep counting -->
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Wife's age</label>
                    <input type="number" name="wife_age" class="form-control" value="{{ old('wife_age') }}" min="1" max="100" placeholder="yrs">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Husband's age</label>
                    <input type="number" name="husband_age" class="form-control" value="{{ old('husband_age') }}" min="1" max="100" placeholder="yrs">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Married since</label>
                    <input type="number" name="years_married" class="form-control" value="{{ old('years_married') }}" min="0" max="80" placeholder="yrs">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Trying since</label>
                    <input type="number" name="years_trying" class="form-control" value="{{ old('years_trying') }}" min="0" max="60" placeholder="yrs">
                </div>

                <!-- Location -->
                <div class="col-12"><h6 class="text-primary fw-semibold border-bottom pb-1 mt-2">Location</h6></div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">State</label>
                    <select name="state_id" class="form-select select2" id="stateSelect">
                        <option value="">Select State</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">City</label>
                    <select name="city_id" class="form-select select2" id="citySelect">
                        <option value="">Select City</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Pincode</label>
                    <input type="text" name="pincode" class="form-control" value="{{ old('pincode') }}" maxlength="10">
                </div>

                <!-- Attribution -->
                <div class="col-12"><h6 class="text-primary fw-semibold border-bottom pb-1 mt-2">Attribution & Assignment</h6></div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Lead Source</label>
                    <select name="lead_source_id" class="form-select">
                        <option value="">Select Source</option>
                        @foreach($sources as $s)
                            <option value="{{ $s->id }}" {{ old('lead_source_id')==$s->id?'selected':'' }}>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Center</label>
                    <select name="center_id" class="form-select">
                        <option value="">Select Center</option>
                        @foreach($centers as $c)
                            <option value="{{ $c->id }}" {{ old('center_id', auth()->user()->center_id)==$c->id?'selected':'' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Assign To</label>
                    @php($defaultAssignee = auth()->user()->hasRole(['Telecaller', 'Counsellor', 'Receptionist']) ? auth()->id() : '')
                    <select name="assigned_to" class="form-select">
                        <option value="">{{ $defaultAssignee ? 'Assign to me' : 'Auto Assign' }}</option>
                        @foreach($telecallers as $u)
                            <option value="{{ $u->id }}" {{ old('assigned_to', $defaultAssignee)==$u->id?'selected':'' }}>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Remark -->
                <div class="col-12">
                    <label class="form-label small fw-semibold">Remark</label>
                    <textarea name="remark" class="form-control" rows="3" placeholder="Initial notes about this lead...">{{ old('remark') }}</textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Save Lead
                </button>
                <a href="{{ route('leads.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

</div>
</div>
@endsection

@push('scripts')
<script>
$('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
$('.flatpickr').flatpickr({ enableTime: true, altInput: true, altFormat: 'd-m-Y h:i K', dateFormat: 'Y-m-d H:i' });
</script>
@endpush
