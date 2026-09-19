<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A WhatsApp Flow: a multi-screen form that runs inside the chat.
 *
 * `flow_json` is the local source of truth while a flow is a DRAFT. Once
 * PUBLISHED, Meta freezes its copy — the only way to change a published flow
 * is to deprecate it and publish a replacement.
 */
class WhatsappFlow extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'channel_account_id', 'external_id', 'name', 'slug', 'description',
        'status', 'categories', 'json_version', 'data_api_version', 'endpoint_uri',
        'flow_json', 'purpose', 'field_map',
        'lead_source_id', 'campaign_id', 'center_id', 'assign_to',
        'auto_create_lead', 'auto_book_appointment',
        'preview_url', 'preview_expires_at', 'validation_errors', 'sync_error',
        'sent_count', 'response_count', 'created_by', 'published_at', 'synced_at',
    ];

    protected $casts = [
        'categories'            => 'array',
        'field_map'             => 'array',
        'validation_errors'     => 'array',
        'auto_create_lead'      => 'boolean',
        'auto_book_appointment' => 'boolean',
        'preview_expires_at'    => 'datetime',
        'published_at'          => 'datetime',
        'synced_at'             => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WhatsappFlow $flow) {
            $flow->slug = $flow->slug ?: Str::slug($flow->name ?: 'flow') . '-' . Str::lower(Str::random(4));
        });
    }

    public function channelAccount(): BelongsTo { return $this->belongsTo(ChannelAccount::class); }
    public function source(): BelongsTo         { return $this->belongsTo(LeadSource::class, 'lead_source_id'); }
    public function campaign(): BelongsTo       { return $this->belongsTo(Campaign::class); }
    public function center(): BelongsTo         { return $this->belongsTo(Center::class); }
    public function assignee(): BelongsTo       { return $this->belongsTo(User::class, 'assign_to'); }
    public function creator(): BelongsTo        { return $this->belongsTo(User::class, 'created_by'); }

    public function sessions(): HasMany  { return $this->hasMany(WhatsappFlowSession::class); }
    public function responses(): HasMany { return $this->hasMany(WhatsappFlowResponse::class); }

    public function scopePublished($query) { return $query->where('status', 'PUBLISHED'); }
    public function scopeSendable($query)  { return $query->where('status', 'PUBLISHED')->whereNotNull('external_id'); }

    public function isPublished(): bool { return $this->status === 'PUBLISHED'; }
    public function isDraft(): bool     { return $this->status === 'DRAFT'; }

    /** An endpoint flow calls back into the CRM between screens. */
    public function isEndpointFlow(): bool
    {
        return ! empty($this->endpoint_uri);
    }

    public function canSend(): bool
    {
        return $this->isPublished() && $this->external_id;
    }

    /** Why the send button is disabled, phrased for the agent. */
    public function blockReason(): ?string
    {
        return match (true) {
            ! $this->external_id  => 'This flow has not been created on Meta yet.',
            $this->isDraft()      => 'This flow is still a draft. Publish it before sending.',
            $this->status === 'DEPRECATED' => 'This flow is deprecated and can no longer be sent.',
            $this->status === 'BLOCKED'    => 'Meta has blocked this flow. Check the validation errors.',
            $this->status === 'THROTTLED'  => 'Meta has throttled this flow for quality reasons.',
            default => null,
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'PUBLISHED'  => 'success',
            'DRAFT'      => 'warning',
            'DEPRECATED' => 'secondary',
            'BLOCKED', 'THROTTLED' => 'danger',
            default      => 'secondary',
        };
    }

    public function decodedJson(): array
    {
        return json_decode((string) $this->flow_json, true) ?: [];
    }

    /** The screen a `navigate` send should open on. */
    public function firstScreen(): ?string
    {
        return $this->decodedJson()['screens'][0]['id'] ?? null;
    }

    /**
     * Every screen id, for the send form and for validating an endpoint reply.
     *
     * @return array<int, string>
     */
    public function screenIds(): array
    {
        return array_values(array_filter(array_map(
            fn ($screen) => $screen['id'] ?? null,
            $this->decodedJson()['screens'] ?? [],
        )));
    }

    /**
     * Field names the flow collects, read out of its Form children so the
     * mapping UI does not have to be maintained by hand.
     *
     * @return array<int, string>
     */
    public function fieldNames(): array
    {
        $fields = [];

        $walk = function (array $nodes) use (&$walk, &$fields): void {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $type = $node['type'] ?? null;
                $name = $node['name'] ?? null;

                // Form and Footer carry a name but collect nothing themselves.
                if ($name && ! in_array($type, ['Form', 'Footer', 'EmbeddedLink'], true)) {
                    $fields[] = $name;
                }

                if (! empty($node['children']) && is_array($node['children'])) {
                    $walk($node['children']);
                }
            }
        };

        foreach ($this->decodedJson()['screens'] ?? [] as $screen) {
            $walk($screen['layout']['children'] ?? []);
        }

        return array_values(array_unique($fields));
    }

    /** Flow field -> CRM attribute, falling back to the starter's mapping. */
    public function effectiveFieldMap(): array
    {
        if (! empty($this->field_map)) {
            return $this->field_map;
        }

        return config("whatsapp_flows.starters.{$this->purpose}.field_map", []);
    }

    public function completionRate(): float
    {
        return $this->sent_count > 0
            ? round(($this->response_count / $this->sent_count) * 100, 1)
            : 0.0;
    }
}
