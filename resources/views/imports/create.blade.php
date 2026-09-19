@extends('layouts.app')

@section('title', 'Import Leads')

@section('content')
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Import Leads from CSV</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('imports.sample') }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-download me-1"></i> Download Sample CSV
            </a>
            <a href="{{ route('imports.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-clock-history me-1"></i> Import History
            </a>
        </div>
    </div>

    <div class="row g-4">

        {{-- Upload Form --}}
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header py-2">
                    <strong>Upload CSV File</strong>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" id="importForm">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label">CSV File <span class="text-danger">*</span></label>
                            <input type="file" name="csv_file" id="csvFile" class="form-control @error('csv_file') is-invalid @enderror"
                                   accept=".csv,.txt" required>
                            @error('csv_file')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Max 20 MB · UTF-8 encoded · First row must be column headers</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Assign to Center</label>
                            <select name="center_id" class="form-select">
                                <option value="">— No specific center —</option>
                                @foreach($centers as $c)
                                    <option value="{{ $c->id }}" {{ old('center_id') == $c->id ? 'selected' : '' }}>
                                        {{ $c->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Default Lead Source <small class="text-muted">(used when CSV row has no source)</small></label>
                            <select name="default_source_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($sources as $s)
                                    <option value="{{ $s->id }}" {{ old('default_source_id') == $s->id ? 'selected' : '' }}>
                                        {{ $s->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">If duplicate mobile found</label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="on_duplicate" value="skip" id="dupSkip" checked>
                                    <label class="form-check-label" for="dupSkip">Skip (keep existing)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="on_duplicate" value="update" id="dupUpdate">
                                    <label class="form-check-label" for="dupUpdate">Update existing</label>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="spinner"></span>
                            <i class="bi bi-upload me-1" id="uploadIcon"></i>
                            Start Import
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Instructions --}}
        <div class="col-lg-5">
            <div class="card shadow-sm border-info">
                <div class="card-header py-2 bg-info bg-opacity-10">
                    <strong><i class="bi bi-info-circle me-1"></i>CSV Format Guide</strong>
                </div>
                <div class="card-body small">
                    <p class="mb-2">Download the <a href="{{ route('imports.sample') }}" class="fw-semibold">sample CSV</a> to see the exact format expected.</p>

                    <table class="table table-sm table-bordered mb-3">
                        <thead class="table-light">
                            <tr><th>Column</th><th>Required</th><th>Notes</th></tr>
                        </thead>
                        <tbody>
                            <tr><td><code>name</code></td>       <td><span class="badge bg-danger">Yes</span></td><td>Lead's full name</td></tr>
                            <tr><td><code>mobile</code></td>     <td><span class="badge bg-danger">Yes</span></td><td>7–15 digits</td></tr>
                            <tr><td><code>alt_mobile</code></td> <td>No</td><td>Alternate number</td></tr>
                            <tr><td><code>email</code></td>      <td>No</td><td>Email address</td></tr>
                            <tr><td><code>gender</code></td>     <td>No</td><td>male / female / other</td></tr>
                            <tr><td><code>age</code></td>        <td>No</td><td>Numeric</td></tr>
                            <tr><td><code>state</code></td>      <td>No</td><td>State name (exact)</td></tr>
                            <tr><td><code>city</code></td>       <td>No</td><td>City name (exact)</td></tr>
                            <tr><td><code>pincode</code></td>    <td>No</td><td>6-digit PIN</td></tr>
                            <tr><td><code>source</code></td>     <td>No</td><td>Lead source name</td></tr>
                            <tr><td><code>remark</code></td>     <td>No</td><td>Any notes</td></tr>
                            <tr><td><code>lead_date</code></td>  <td>No</td><td>YYYY-MM-DD</td></tr>
                        </tbody>
                    </table>

                    <div class="alert alert-warning py-2 mb-0 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Rows that fail validation are <strong>not imported</strong>. After upload you can download an
                        <strong>error CSV</strong> containing only the failed rows with an <code>error_reason</code>
                        column — fix them and re-upload.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$('#importForm').on('submit', function () {
    $('#submitBtn').prop('disabled', true);
    $('#spinner').removeClass('d-none');
    $('#uploadIcon').addClass('d-none');
});

// Preview filename
$('#csvFile').on('change', function () {
    const name = this.files[0]?.name ?? '';
    $(this).next('.form-text').text(name ? `Selected: ${name}` : 'Max 20 MB · UTF-8 encoded · First row must be column headers');
});
</script>
@endpush
