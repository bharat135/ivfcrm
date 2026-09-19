<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Smartflo IVR, reference data for broadcast/inbound routing. */
class TataIvr extends Model
{
    protected $fillable = ['smartflo_id', 'name', 'menu', 'synced_at', 'raw'];

    protected $casts = [
        'menu'      => 'array',
        'synced_at' => 'datetime',
        'raw'       => 'array',
    ];
}
