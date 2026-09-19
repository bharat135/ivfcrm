<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageSubmission extends Model
{
    protected $fillable = [
        'landing_page_id', 'lead_id', 'payload', 'utm',
        'ip_address', 'user_agent', 'referrer',
    ];

    protected $casts = [
        'payload' => 'array',
        'utm'     => 'array',
    ];

    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
