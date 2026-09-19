<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadSource extends Model
{
    protected $fillable = [
        'name', 'slug', 'channel', 'is_paid', 'is_active', 'config',
    ];

    protected $casts = [
        'is_paid'   => 'boolean',
        'is_active' => 'boolean',
        'config'    => 'array',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
