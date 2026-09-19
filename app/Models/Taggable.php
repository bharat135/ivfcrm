<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Taggable extends Model
{
    protected $fillable = ['tag_id', 'taggable_id', 'taggable_type'];

    public function tag(): BelongsTo { return $this->belongsTo(Tag::class); }
    public function taggable(): MorphTo { return $this->morphTo(); }
}
