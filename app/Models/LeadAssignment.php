<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAssignment extends Model
{
    protected $fillable = [
        'lead_id', 'assigned_to', 'assigned_by', 'method', 'assigned_at', 'reassigned_at',
    ];

    protected $casts = [
        'assigned_at'   => 'datetime',
        'reassigned_at' => 'datetime',
    ];

    public function lead(): BelongsTo       { return $this->belongsTo(Lead::class); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
}
