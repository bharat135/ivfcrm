<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadImportError extends Model
{
    protected $fillable = ['lead_import_id', 'row_number', 'row_data', 'error'];

    protected $casts = ['row_data' => 'array'];

    public function import(): BelongsTo { return $this->belongsTo(LeadImport::class, 'lead_import_id'); }
}
