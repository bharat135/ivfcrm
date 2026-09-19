<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Live transcript captured in the agent's browser during a call, plus the
 * one-line summary that is offered as the call note.
 */
class CallTranscript extends Model
{
    protected $fillable = [
        'call_log_id', 'provider', 'language', 'segments', 'transcript',
        'summary', 'summary_source', 'status', 'started_at', 'ended_at',
    ];

    protected $casts = [
        'segments'   => 'array',
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    /** Flatten the segments into one readable transcript. */
    public function renderTranscript(): string
    {
        $lines = [];

        foreach ($this->segments ?? [] as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $speaker = $segment['speaker'] ?? null;
            $lines[] = ($speaker ? ucfirst($speaker) . ': ' : '') . $text;
        }

        return implode("\n", $lines);
    }

    public function wordCount(): int
    {
        return str_word_count((string) $this->transcript);
    }
}
