{{-- Registering here creates the enquiry as well, so a walk-in is attributed
     and counted in the funnel exactly like a paid lead. --}}
<div class="modal fade" id="addPatientModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Register patient</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert d-flex mb-3">
                    <i class="bi bi-info-circle mt-1"></i>
                    <div>This also opens an enquiry record, so the patient appears in the funnel and in source reports.</div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Patient name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="pName" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Mobile <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="pMobile" inputmode="numeric" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Email</label>
                        <input type="email" class="form-control" id="pEmail">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Gender</label>
                        <select class="form-select" id="pGender">
                            <option value="female">Female</option>
                            <option value="male">Male</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Age</label>
                        <input type="number" class="form-control" id="pAge" min="1" max="100">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Center</label>
                        <select class="form-select" id="pCenter">
                            @foreach($centers as $c)
                                <option value="{{ $c->id }}" {{ auth()->user()->center_id == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Source</label>
                        <select class="form-select" id="pSource">
                            <option value="">Walk-in</option>
                            @foreach($sources as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Husband / partner name</label>
                        <input type="text" class="form-control" id="pHusband">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" id="savePatient">Register</button>
            </div>
        </div>
    </div>
</div>
