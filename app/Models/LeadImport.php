<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadImport extends Model
{
    protected $fillable = [
        'user_id', 'center_id', 'original_filename', 'stored_path', 'status',
        'column_mapping', 'options', 'total_rows', 'imported_rows',
        'duplicate_rows', 'failed_rows', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'options'        => 'array',
        'started_at'     => 'datetime',
        'finished_at'    => 'datetime',
    ];

    public function user(): BelongsTo   { return $this->belongsTo(User::class); }
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function errors(): HasMany   { return $this->hasMany(LeadImportError::class); }
}
