<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Smartflo department (ring group / queue), reference data for routing. */
class TataDepartment extends Model
{
    protected $fillable = ['smartflo_id', 'name', 'ring_strategy', 'agent_count', 'agents', 'synced_at', 'raw'];

    protected $casts = [
        'agents'    => 'array',
        'synced_at' => 'datetime',
        'raw'       => 'array',
    ];
}
