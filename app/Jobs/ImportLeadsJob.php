<?php

namespace App\Jobs;

use App\Models\LeadImport;
use App\Services\LeadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class ImportLeadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 600;

    public function __construct(private int $importId) {}

    public function handle(LeadService $service): void
    {
        $import = LeadImport::findOrFail($this->importId);
        $import->update(['status' => 'processing', 'started_at' => now()]);

        $path    = Storage::path($import->stored_path);
        $mapping = $import->column_mapping ?? [];
        $options = $import->options ?? [];

        $csv = Reader::createFromPath($path, 'r');
        $csv->setHeaderOffset(0);

        $total = $imported = $duplicates = $failed = 0;

        foreach ($csv->getRecords() as $row) {
            $total++;
            try {
                $data = [];
                foreach ($mapping as $csvCol => $modelCol) {
                    if (!empty($row[$csvCol])) {
                        $data[$modelCol] = trim($row[$csvCol]);
                    }
                }

                if (empty($data['mobile'])) {
                    $failed++;
                    \App\Models\LeadImportError::create([
                        'lead_import_id' => $import->id,
                        'row_number'     => $total,
                        'row_data'       => $row,
                        'error'          => 'Mobile number is required.',
                    ]);
                    continue;
                }

                $data['center_id']      = $import->center_id;
                $data['lead_source_id'] = $options['default_source_id'] ?? null;

                $result = $service->create($data, true);

                if ($result->wasRecentlyCreated) {
                    $imported++;
                } else {
                    $duplicates++;
                }
            } catch (\Throwable $e) {
                $failed++;
                \App\Models\LeadImportError::create([
                    'lead_import_id' => $import->id,
                    'row_number'     => $total,
                    'row_data'       => $row,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        $import->update([
            'status'        => 'completed',
            'total_rows'    => $total,
            'imported_rows' => $imported,
            'duplicate_rows'=> $duplicates,
            'failed_rows'   => $failed,
            'finished_at'   => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        LeadImport::where('id', $this->importId)->update([
            'status'      => 'failed',
            'finished_at' => now(),
        ]);
    }
}
