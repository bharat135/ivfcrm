<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A telecaller's leave, or (user_id null) a clinic-wide holiday.
 */
class AgentLeave extends Model
{
    protected $fillable = ['user_id', 'starts_on', 'ends_on', 'type', 'reason', 'created_by'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];

    public const TYPES = ['leave' => 'Leave', 'holiday' => 'Holiday (whole clinic)'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Leaves (personal or clinic-wide) covering a calendar date. */
    public function scopeOnDate($query, Carbon|string $date)
    {
        $day = Carbon::parse($date)->toDateString();

        return $query->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day);
    }

    /** Applies to this user: their own leave or a clinic-wide holiday. */
    public function scopeAffecting($query, int $userId)
    {
        return $query->where(fn ($q) => $q->where('user_id', $userId)->orWhereNull('user_id'));
    }

    public function isHoliday(): bool
    {
        return $this->user_id === null || $this->type === 'holiday';
    }
}
