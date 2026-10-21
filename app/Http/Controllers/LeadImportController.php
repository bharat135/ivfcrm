<?php

namespace App\Http\Controllers;

use App\Models\Center;
use App\Models\City;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportError;
use App\Models\LeadSource;
use App\Models\State;
use App\Support\PhoneNumber;
use Carbon\Carbon;
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
            'csv_file'        => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
            'center_id'       => ['nullable', 'exists:centers,id'],
            'default_source_id' => ['nullable', 'exists:lead_sources,id'],
            'on_duplicate'    => ['required', 'in:skip,update'],
        ], [
            'csv_file.mimes' => 'Upload a CSV or Excel file (.csv, .xlsx, .xls).',
        ]);

        $file          = $request->file('csv_file');
        $originalName  = $file->getClientOriginalName();
        // Keep the real extension: the guessed one turns a .csv into .txt and
        // tells the reader nothing about whether it is a spreadsheet.
        $extension     = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $storedPath    = $file->storeAs('imports', Str::random(40) . '.' . $extension, 'local');

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

        try {
            [$total, $imported, $duplicates, $failed, $errors] = $this->processFile($import);
        } catch (\Throwable $e) {
            report($e);
            $import->update(['status' => 'failed', 'finished_at' => now()]);

            return redirect()->route('imports.create')
                ->with('error', 'The file could not be read: ' . $e->getMessage());
        }

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

    // Header spellings people actually use, mapped to the importer's fields.
    private const HEADER_ALIASES = [
        'name'       => ['name', 'full_name', 'patient_name', 'lead_name', 'customer_name', 'wife_name'],
        'mobile'     => ['mobile', 'mobile_no', 'mobile_number', 'phone', 'phone_no', 'phone_number',
                         'contact', 'contact_no', 'contact_number', 'number', 'whatsapp', 'whatsapp_no'],
        'alt_mobile' => ['alt_mobile', 'alternate_mobile', 'alternate_number', 'alt_phone', 'mobile_2', 'phone_2'],
        'email'      => ['email', 'email_id', 'email_address'],
        'gender'     => ['gender', 'sex'],
        'age'        => ['age'],
        'state'      => ['state'],
        'city'       => ['city'],
        'pincode'    => ['pincode', 'pin_code', 'pin', 'zip'],
        'source'     => ['source', 'lead_source'],
        'center'     => ['center', 'centre', 'branch'],
        'remark'     => ['remark', 'remarks', 'note', 'notes', 'comment', 'comments'],
        'lead_date'  => ['lead_date', 'date', 'enquiry_date', 'created_date', 'created_at'],
    ];

    private function processFile(LeadImport $import): array
    {
        $rows = $this->readRows(Storage::disk('local')->path($import->stored_path));
        $opts = $import->options ?? [];

        $headers = array_shift($rows);
        if (! $headers) {
            throw new \RuntimeException('The file is empty.');
        }

        $columns = $this->mapHeaders($headers);
        if (! in_array('mobile', $columns, true)) {
            throw new \RuntimeException('No mobile column found. The first row must hold column headers such as "name" and "mobile".');
        }

        $total = $imported = $duplicates = $failed = 0;
        $errors = [];

        // Pre-load lookup caches
        $sourceCache = LeadSource::pluck('id', 'name')
            ->mapWithKeys(fn($id, $name) => [strtolower($name) => $id])
            ->toArray();

        $stateCache = State::pluck('id', 'name')
            ->mapWithKeys(fn($id, $name) => [strtolower($name) => $id])
            ->toArray();

        $centerCache = [];
        foreach (Center::get(['id', 'name', 'code']) as $c) {
            $centerCache[strtolower($c->name)] = $c->id;
            if ($c->code) {
                $centerCache[strtolower($c->code)] = $c->id;
            }
        }

        foreach ($rows as $row) {
            if (count(array_filter($row, fn($v) => $v !== '')) === 0) {
                continue; // skip blank lines
            }

            $total++;

            // Original headers -> values, for the error file
            $data = [];
            foreach ($headers as $i => $h) {
                $data[$h !== '' ? $h : 'column_' . ($i + 1)] = $row[$i] ?? '';
            }

            try {
                [$ok, $reason, $leadData] = $this->mapAndValidate(
                    $row, $headers, $columns, $import, $sourceCache, $stateCache, $centerCache
                );

                if ($ok) {
                    $existing = Lead::where('dedup_hash', PhoneNumber::dedupHash($leadData['mobile']))->first();

                    if ($existing) {
                        if (($opts['on_duplicate'] ?? 'skip') === 'update') {
                            $existing->update(array_filter($leadData, fn($v) => $v !== null && $v !== ''));
                        }
                        $duplicates++;
                    } else {
                        Lead::create($leadData);
                        $imported++;
                    }

                    continue;
                }
            } catch (\Throwable $e) {
                report($e);
                $reason = 'Could not save this row: ' . Str::limit($e->getMessage(), 200);
            }

            $failed++;
            $errors[] = ['row_data' => $data, 'error' => $reason, 'row_number' => $total];
            LeadImportError::create([
                'lead_import_id' => $import->id,
                'row_number'     => $total,
                'row_data'       => $data,
                'error'          => $reason,
            ]);
        }

        return [$total, $imported, $duplicates, $failed, $errors];
    }

    /**
     * Every row of the file as a list of clean UTF-8 strings. Excel workbooks
     * are read with PhpSpreadsheet; text files may be comma, tab, semicolon or
     * pipe separated and saved as UTF-8, UTF-16 or Windows-1252 (Excel's
     * "CSV" and "Text" exports).
     */
    private function readRows(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false); // keep number formats so dates read as dates
            $sheet = $reader->load($path)->getActiveSheet();
            $rows  = $sheet->toArray('', true, true, false);
        } else {
            $raw = file_get_contents($path);

            if (str_starts_with($raw, "\xFF\xFE")) {
                $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
            } elseif (str_starts_with($raw, "\xFE\xFF")) {
                $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
            } else {
                if (str_starts_with($raw, "\xEF\xBB\xBF")) {
                    $raw = substr($raw, 3);
                }
                if (! mb_check_encoding($raw, 'UTF-8')) {
                    $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
                }
            }

            $firstLine = strtok($raw, "\r\n") ?: '';
            $delimiter = collect([',', "\t", ';', '|'])
                ->sortByDesc(fn($d) => substr_count($firstLine, $d))
                ->first();

            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $raw);
            rewind($handle);

            $rows = [];
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
        }

        return array_map(fn($row) => array_map(fn($v) => $this->cleanCell($v), $row), $rows);
    }

    private function cleanCell($value): string
    {
        $value = mb_scrub((string) ($value ?? ''), 'UTF-8');

        // Non-breaking spaces from Excel / web copies count as spaces
        return trim(str_replace("\u{00A0}", ' ', $value));
    }

    /** Column index => field name, or null for a column the importer does not know. */
    private function mapHeaders(array $headers): array
    {
        $lookup = [];
        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $lookup[$alias] = $field;
            }
        }

        $columns = [];
        foreach ($headers as $i => $header) {
            $key   = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($header)), '_');
            $field = $lookup[$key] ?? null;

            // First column wins when two map to the same field
            $columns[$i] = ($field && ! in_array($field, $columns, true)) ? $field : null;
        }

        return $columns;
    }

    private function mapAndValidate(array $row, array $headers, array $columns, LeadImport $import,
                                    array $sourceCache, array $stateCache, array $centerCache): array
    {
        $values = $extras = [];
        foreach ($row as $i => $value) {
            $field = $columns[$i] ?? null;
            if ($field) {
                $values[$field] = $value;
            } elseif ($value !== '' && strtolower($headers[$i] ?? '') !== 'error_reason') {
                // Columns with no lead field (dispositions, call notes...) are kept in the remark
                $extras[] = (($headers[$i] ?? '') !== '' ? $headers[$i] . ': ' : '') . $value;
            }
        }

        $mobile = $values['mobile'] ?? '';
        if ($mobile === '') {
            return [false, 'Mobile number is required.', []];
        }

        // Basic mobile sanity: 7-15 digits after stripping non-digits
        $digits = preg_replace('/\D/', '', $mobile);
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return [false, 'Mobile number must be 7–15 digits.', []];
        }

        $name = ($values['name'] ?? '') ?: 'Unknown';

        $gender = strtolower($values['gender'] ?? '');
        $gender = ['m' => 'male', 'f' => 'female'][$gender] ?? $gender;
        if ($gender && !in_array($gender, ['male', 'female', 'other'])) {
            return [false, "Gender must be 'male', 'female', or 'other' (got: {$gender}).", []];
        }

        $age = is_numeric($values['age'] ?? null) ? (int) $values['age'] : null;

        // Resolve lead source; a value that is not a known source is kept in the remark
        $sourceId = $import->options['default_source_id'] ?? null;
        $sourceName = strtolower($values['source'] ?? '');
        if ($sourceName !== '') {
            if (isset($sourceCache[$sourceName])) {
                $sourceId = $sourceCache[$sourceName];
            } else {
                $extras[] = 'Source: ' . $values['source'];
            }
        }

        $centerId = $import->center_id;
        $centerName = strtolower($values['center'] ?? '');
        if ($centerName !== '') {
            if (isset($centerCache[$centerName])) {
                $centerId = $centerCache[$centerName];
            } else {
                $extras[] = 'Center: ' . $values['center'];
            }
        }

        // Resolve state
        $stateId = null;
        $stateName = strtolower($values['state'] ?? '');
        if ($stateName && isset($stateCache[$stateName])) {
            $stateId = $stateCache[$stateName];
        }

        // Resolve city (only attempt if state found, avoid N+1 by accepting null city)
        $cityId = null;
        $cityName = $values['city'] ?? '';
        if ($cityName && $stateId) {
            $cityId = City::where('state_id', $stateId)
                ->whereRaw('LOWER(name) = ?', [strtolower($cityName)])
                ->value('id');
        }
        if ($cityName && ! $cityId) {
            $extras[] = 'City: ' . $cityName;
        }

        $remark = trim(implode(' | ', array_filter([$values['remark'] ?? '', ...$extras])));

        return [true, null, [
            'name'          => Str::limit($name, 190, ''),
            'mobile'        => $mobile,
            'alt_mobile'    => ($values['alt_mobile'] ?? '') ?: null,
            'email'         => ($values['email'] ?? '') ?: null,
            'gender'        => $gender ?: null,
            'age'           => $age,
            'state_id'      => $stateId,
            'city_id'       => $cityId,
            'pincode'       => ($values['pincode'] ?? '') ?: null,
            'remark'        => $remark !== '' ? $remark : null,
            'lead_source_id'=> $sourceId,
            'center_id'     => $centerId,
            'lead_date'     => $this->parseDate($values['lead_date'] ?? '') ?? now(),
            'status'        => 'new',
        ]];
    }

    /** Indian sheets write day first: 01-09-2026 and 01/09/2026 are 1 September. */
    private function parseDate(string $raw): ?Carbon
    {
        if ($raw === '') {
            return null;
        }

        try {
            if (is_numeric($raw) && $raw > 20000 && $raw < 80000) {
                $date = Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw));
            } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})\b/', $raw, $m)) {
                $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
                $date = Carbon::create($year, (int) $m[2], (int) $m[1]);
                if ($date->month !== (int) $m[2]) {
                    return null; // 31/02 rolled over
                }
            } else {
                $date = Carbon::parse($raw);
            }
        } catch (\Throwable) {
            return null;
        }

        return $date->year >= 2000 && $date->year <= 2100 ? $date : null;
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
