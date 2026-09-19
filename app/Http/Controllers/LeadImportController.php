<?php

namespace App\Http\Controllers;

use App\Models\Center;
use App\Models\City;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportError;
use App\Models\LeadSource;
use App\Models\State;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LeadImportController extends Controller
{
    // Canonical CSV headers for the sample file and column detection
    private const SAMPLE_HEADERS = [
        'name', 'mobile', 'alt_mobile', 'email',
        'gender', 'age', 'state', 'city', 'pincode',
        'source', 'remark', 'lead_date',
    ];

    private const SAMPLE_ROW = [
        'Priya Sharma', '9876543210', '9876543211', 'priya@example.com',
        'female', '32', 'Maharashtra', 'Mumbai', '400001',
        'Facebook', 'Interested in IVF treatment', '2026-06-24',
    ];

    public function index()
    {
        $imports = LeadImport::with('user:id,name', 'center:id,name')
            ->where('user_id', auth()->id())
            ->orWhere(fn($q) => $q->whereHas('user', fn($u) => $u->whereNull('id')))
            ->orderByDesc('created_at')
            ->paginate(20);

        // Let management/admin see all imports
        if (auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Management'])) {
            $imports = LeadImport::with('user:id,name', 'center:id,name')
                ->orderByDesc('created_at')
                ->paginate(20);
        }

        return view('imports.index', compact('imports'));
    }

    public function create()
    {
        $this->authorize('create', Lead::class);

        $centers = Center::active()->orderBy('name')->get();
        $sources = LeadSource::where('is_active', true)->orderBy('name')->get();

        return view('imports.create', compact('centers', 'sources'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Lead::class);

        $request->validate([
            'csv_file'        => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            'center_id'       => ['nullable', 'exists:centers,id'],
            'default_source_id' => ['nullable', 'exists:lead_sources,id'],
            'on_duplicate'    => ['required', 'in:skip,update'],
        ]);

        $file          = $request->file('csv_file');
        $originalName  = $file->getClientOriginalName();
        $storedPath    = $file->store('imports', 'local');

        $import = LeadImport::create([
            'user_id'           => auth()->id(),
            'center_id'         => $request->center_id,
            'original_filename' => $originalName,
            'stored_path'       => $storedPath,
            'status'            => 'processing',
            'options'           => [
                'default_source_id' => $request->default_source_id,
                'on_duplicate'      => $request->on_duplicate,
            ],
            'started_at' => now(),
        ]);

        [$total, $imported, $duplicates, $failed, $errors] = $this->processFile($import);

        $errorCsvPath = null;
        if ($failed > 0) {
            $errorCsvPath = $this->writeErrorCsv($import, $errors);
        }

        $opts = $import->options ?? [];
        $opts['error_csv_path'] = $errorCsvPath;

        $import->update([
            'status'         => 'completed',
            'total_rows'     => $total,
            'imported_rows'  => $imported,
            'duplicate_rows' => $duplicates,
            'failed_rows'    => $failed,
            'options'        => $opts,
            'finished_at'    => now(),
        ]);

        return redirect()->route('imports.show', $import)
            ->with('success', "Import complete: {$imported} imported, {$duplicates} duplicate(s), {$failed} failed.");
    }

    public function show(LeadImport $import)
    {
        $import->load('user:id,name', 'center:id,name');
        $errorRows = $import->errors()->orderBy('row_number')->paginate(50);

        return view('imports.show', compact('import', 'errorRows'));
    }

    public function downloadSample(): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ivf_sample_');

        $out = fopen($tmp, 'w');
        // UTF-8 BOM so Excel opens correctly without garbling
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::SAMPLE_HEADERS);
        fputcsv($out, self::SAMPLE_ROW);
        fclose($out);

        return response()->download($tmp, 'leads_import_sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ])->deleteFileAfterSend(true);
    }

    public function downloadErrors(LeadImport $import): BinaryFileResponse
    {
        abort_unless(($import->failed_rows ?? 0) > 0, 404, 'No errors for this import.');

        $basename = 'errors_' . pathinfo($import->original_filename, PATHINFO_FILENAME) . '.csv';

        // Use pre-generated file if available
        $errorPath = $import->options['error_csv_path'] ?? null;
        if ($errorPath && Storage::disk('local')->exists($errorPath)) {
            $absPath = Storage::disk('local')->path($errorPath);
            return response()->download($absPath, $basename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        // Regenerate from DB into a temp file
        $tmp = tempnam(sys_get_temp_dir(), 'ivf_err_');
        $out = fopen($tmp, 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

        $headerWritten = false;
        $import->errors()->orderBy('row_number')->chunk(200, function ($rows) use ($out, &$headerWritten) {
            foreach ($rows as $row) {
                $data = $row->row_data ?? [];
                if (!$headerWritten) {
                    $cols = array_keys($data);
                    $cols[] = 'error_reason';
                    fputcsv($out, $cols);
                    $headerWritten = true;
                }
                $data['error_reason'] = $row->error;
                fputcsv($out, array_values($data));
            }
        });

        if (!$headerWritten) {
            // Edge case: errors table has entries but row_data is null
            fputcsv($out, ['row_number', 'error_reason']);
        }

        fclose($out);

        return response()->download($tmp, $basename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ])->deleteFileAfterSend(true);
    }

    // -----------------------------------------------------------------------
    // Processing logic
    // -----------------------------------------------------------------------

    private function processFile(LeadImport $import): array
    {
        $path = Storage::disk('local')->path($import->stored_path);
        $opts = $import->options ?? [];

        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException('Cannot open uploaded file.');
        }

        $headers = null;
        $total = $imported = $duplicates = $failed = 0;
        $errors = [];

        // Pre-load lookup caches
        $sourceCache = LeadSource::pluck('id', 'name')
            ->mapWithKeys(fn($id, $name) => [strtolower($name) => $id])
            ->toArray();

        $stateCache = State::pluck('id', 'name')
            ->mapWithKeys(fn($id, $name) => [strtolower($name) => $id])
            ->toArray();

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            if ($headers === null) {
                // Normalize header names
                $headers = array_map(fn($h) => strtolower(trim(str_replace(' ', '_', $h))), $row);
                continue;
            }

            if (count(array_filter($row)) === 0) {
                continue; // skip blank lines
            }

            $total++;
            $data = array_combine($headers, array_pad($row, count($headers), ''));

            [$ok, $reason, $leadData] = $this->mapAndValidate($data, $import, $opts, $sourceCache, $stateCache);

            if (!$ok) {
                $failed++;
                $errors[] = ['row_data' => $data, 'error' => $reason, 'row_number' => $total];
                LeadImportError::create([
                    'lead_import_id' => $import->id,
                    'row_number'     => $total,
                    'row_data'       => $data,
                    'error'          => $reason,
                ]);
                continue;
            }

            $hash = sha1(preg_replace('/\D/', '', $leadData['mobile']));

            $existing = Lead::where('dedup_hash', $hash)->first();

            if ($existing) {
                if (($opts['on_duplicate'] ?? 'skip') === 'update') {
                    $existing->update(array_filter($leadData, fn($v) => $v !== null && $v !== ''));
                }
                $duplicates++;
            } else {
                Lead::create($leadData);
                $imported++;
            }
        }

        fclose($handle);

        return [$total, $imported, $duplicates, $failed, $errors];
    }

    private function mapAndValidate(array $row, LeadImport $import, array $opts, array $sourceCache, array $stateCache): array
    {
        $mobile = trim($row['mobile'] ?? '');
        if (empty($mobile)) {
            return [false, 'Mobile number is required.', []];
        }

        // Basic mobile sanity: 7-15 digits after stripping non-digits
        $digits = preg_replace('/\D/', '', $mobile);
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return [false, 'Mobile number must be 7–15 digits.', []];
        }

        $name = trim($row['name'] ?? '');
        if (empty($name)) {
            return [false, 'Name is required.', []];
        }

        $gender = strtolower(trim($row['gender'] ?? ''));
        if ($gender && !in_array($gender, ['male', 'female', 'other'])) {
            return [false, "Gender must be 'male', 'female', or 'other' (got: {$gender}).", []];
        }

        $age = isset($row['age']) && is_numeric(trim($row['age'])) ? (int)$row['age'] : null;

        // Resolve lead source
        $sourceId = $import->options['default_source_id'] ?? null;
        $sourceName = strtolower(trim($row['source'] ?? ''));
        if ($sourceName && isset($sourceCache[$sourceName])) {
            $sourceId = $sourceCache[$sourceName];
        }

        // Resolve state
        $stateId = null;
        $stateName = strtolower(trim($row['state'] ?? ''));
        if ($stateName && isset($stateCache[$stateName])) {
            $stateId = $stateCache[$stateName];
        }

        // Resolve city (only attempt if state found, avoid N+1 by accepting null city)
        $cityId = null;
        $cityName = trim($row['city'] ?? '');
        if ($cityName && $stateId) {
            $cityId = City::where('state_id', $stateId)
                ->whereRaw('LOWER(name) = ?', [strtolower($cityName)])
                ->value('id');
        }

        // Lead date
        $leadDate = null;
        $rawDate = trim($row['lead_date'] ?? '');
        if ($rawDate) {
            try {
                $leadDate = \Carbon\Carbon::parse($rawDate)->toDateString();
            } catch (\Throwable) {
                $leadDate = null;
            }
        }

        return [true, null, [
            'name'          => $name,
            'mobile'        => $mobile,
            'alt_mobile'    => trim($row['alt_mobile'] ?? '') ?: null,
            'email'         => trim($row['email'] ?? '') ?: null,
            'gender'        => $gender ?: null,
            'age'           => $age,
            'state_id'      => $stateId,
            'city_id'       => $cityId,
            'pincode'       => trim($row['pincode'] ?? '') ?: null,
            'remark'        => trim($row['remark'] ?? '') ?: null,
            'lead_source_id'=> $sourceId,
            'center_id'     => $import->center_id,
            'lead_date'     => $leadDate ?? now(),
            'status'        => 'new',
        ]];
    }

    private function writeErrorCsv(LeadImport $import, array $errors): string
    {
        $dir  = 'imports/errors';
        $name = 'errors_' . $import->id . '_' . Str::random(8) . '.csv';
        $path = $dir . '/' . $name;

        Storage::disk('local')->makeDirectory($dir);

        $tmpPath = Storage::disk('local')->path($path);
        $out = fopen($tmpPath, 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

        $headerWritten = false;
        foreach ($errors as $err) {
            $data = $err['row_data'];
            if (!$headerWritten) {
                $cols = array_keys($data);
                $cols[] = 'error_reason';
                fputcsv($out, $cols);
                $headerWritten = true;
            }
            $data['error_reason'] = $err['error'];
            fputcsv($out, array_values($data));
        }

        fclose($out);

        return $path;
    }
}
