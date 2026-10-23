<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A DID on the Smartflo account. */
class TataNumber extends Model
{
    protected $fillable = [
        'smartflo_id', 'did', 'name', 'description', 'alias', 'destination', 'destination_name',
        'center_id', 'user_id', 'is_default', 'synced_at', 'raw',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The caller id to present for an agent: their own DID, else their centre's, else the default, else none. */
    public static function callerIdFor(?int $centerId, ?int $userId = null): ?string
    {
        if ($userId && $did = static::where('user_id', $userId)->value('did')) {
            return $did;
        }

        if ($centerId && $did = static::where('center_id', $centerId)->value('did')) {
            return $did;
        }

        return static::where('is_default', true)->value('did');
    }
}
