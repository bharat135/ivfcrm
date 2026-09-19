<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'center_id',
        'group',
        'key',
        'value',
        'type',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }
}
