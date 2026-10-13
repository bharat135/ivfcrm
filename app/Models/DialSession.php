<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bulk-calling run: an ordered queue of leads one agent works through.
 *
 * The CRM places a click-to-call for the lead under the cursor, the agent
 * saves the outcome, and the cursor moves on — a progressive dialler paced by
 * the agent rather than by the carrier.
 */
class DialSession extends Model
{
    protected $fillable = [
        'user_id', 'status', 'lead_ids', 'cursor', 'dialed_count', 'skipped_count', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'lead_ids'      => 'array',
            'cursor'        => 'integer',
            'dialed_count'  => 'integer',
            'skipped_count' => 'integer',
            'started_at'    => 'datetime',
            'ended_at'      => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** A run left open longer than this is treated as abandoned. */
    public const STALE_HOURS = 12;

    /**
     * The agent's current run, if they have one. A run left open from an
     * earlier day is closed and ignored, so a forgotten session never keeps
     * hijacking dispositions or showing the bulk banner.
     */
    public static function activeFor(User $user): ?self
    {
        $session = static::query()->active()->where('user_id', $user->id)->latest('id')->first();

        if ($session && $session->started_at && $session->started_at->lt(now()->subHours(self::STALE_HOURS))) {
            $session->forceFill(['status' => 'stopped', 'ended_at' => now()])->save();

            return null;
        }

        return $session;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function total(): int
    {
        return count($this->lead_ids ?? []);
    }

    /** 1-based position shown to the agent. */
    public function position(): int
    {
        return min($this->cursor + 1, max($this->total(), 1));
    }

    public function currentLeadId(): ?int
    {
        $id = ($this->lead_ids ?? [])[$this->cursor] ?? null;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Is this the lead under the cursor? A duplicate enquiry shares the
     * patient file with the original, and the profile always opens the
     * original, so a match on the patient counts too.
     */
    public function isCurrent(Lead|int|null $lead): bool
    {
        $currentId = $this->currentLeadId();

        if ($lead === null || $currentId === null || ! $this->isActive()) {
            return false;
        }

        $lead = $lead instanceof Lead ? $lead : Lead::find($lead);

        if (! $lead) {
            return false;
        }

        if ((int) $lead->id === $currentId) {
            return true;
        }

        $current = Lead::find($currentId);

        return $current !== null
            && $current->patient_id !== null
            && (int) $current->patient_id === (int) $lead->patient_id;
    }

    /**
     * Move to the next lead. Counts the one just left as dialled or skipped,
     * and ends the run when the queue is exhausted.
     */
    public function advance(bool $skipped = false): void
    {
        $this->cursor = $this->cursor + 1;
        $skipped ? $this->skipped_count++ : $this->dialed_count++;

        if ($this->cursor >= $this->total()) {
            $this->status   = 'done';
            $this->ended_at = now();
        }

        $this->save();
    }

    public function stop(): void
    {
        if ($this->isActive()) {
            $this->forceFill(['status' => 'stopped', 'ended_at' => now()])->save();
        }
    }

    /**
     * A saved outcome for the lead under the cursor moves the run on. Returns
     * the URL to open next, or null when no run is waiting on this lead.
     */
    public static function advanceAfter(User $user, Lead|int|null $lead): ?string
    {
        $session = static::activeFor($user);

        if (! $session || ! $session->isCurrent($lead)) {
            return null;
        }

        $session->advance();

        return $session->nextUrl();
    }

    /** Where the agent should go now: the current lead, or back to the list. */
    public function nextUrl(): string
    {
        $leadId = $this->isActive() ? $this->currentLeadId() : null;

        return $leadId
            ? route('leads.show', ['lead' => $leadId, 'autodial' => 1])
            : route('leads.index');
    }
}
