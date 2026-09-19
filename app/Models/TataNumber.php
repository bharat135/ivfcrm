<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A DID on the Smartflo account. */
class TataNumber extends Model
{
    protected $fillable = [
        'smartflo_id', 'did', 'name', 'description', 'alias', 'destination', 'destination_name',
        'center_id', 'is_default', 'synced_at', 'raw',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'synced_at'  => 'datetime',
        'raw'        => 'array',
    ];

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /** The caller id to present for an agent: their center's DID, else the default, else none. */
    public static function callerIdFor(?int $centerId): ?string
    {
        if ($centerId && $did = static::where('center_id', $centerId)->value('did')) {
            return $did;
        }

        return static::where('is_default', true)->value('did');
    }
}
