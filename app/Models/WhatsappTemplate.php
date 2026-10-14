<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappTemplate extends Model
{
    protected $fillable = [
        'channel_account_id', 'name', 'language', 'category', 'status',
        'external_id', 'components', 'body_text', 'variable_count',
        'header_format', 'rejected_reason', 'synced_at',
    ];

    protected $casts = [
        'components'     => 'array',
        'variable_count' => 'integer',
        'synced_at'      => 'datetime',
    ];

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'APPROVED');
    }

    /**
     * Templates belonging to the active default WhatsApp sender. A template
     * only exists in the WABA it was created in, so offering another
     * account's template would fail at send time. With no default account,
     * the templates synced from Settings → Integrations (no account) apply.
     */
    public function scopeForDefaultSender($query)
    {
        $default = ChannelAccount::where('channel', 'whatsapp')
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        return $default
            ? $query->where('channel_account_id', $default->id)
            : $query->whereNull('channel_account_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    /**
     * Pull the flattened body text and the {{n}} count out of Meta's component
     * array so the UI can preview a template without re-parsing it everywhere.
     */
    public static function extractFromComponents(array $components): array
    {
        $body = '';
        $headerFormat = null;

        foreach ($components as $component) {
            $type = strtoupper($component['type'] ?? '');

            if ($type === 'BODY') {
                $body = $component['text'] ?? '';
            }
            if ($type === 'HEADER') {
                $headerFormat = strtoupper($component['format'] ?? 'TEXT');
            }
        }

        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
        $count = empty($matches[1]) ? 0 : max(array_map('intval', $matches[1]));

        return [
            'body_text'      => $body,
            'variable_count' => $count,
            'header_format'  => $headerFormat,
        ];
    }

    /**
     * Placeholders ({{n}}, 1-based) that the supplied values leave empty.
     * Every variable is compulsory: Meta rejects a template whose parameter
     * count differs from the body, and a blank value sends a message with a
     * hole in it.
     *
     * @return int[]
     */
    public function missingVariables(array $params): array
    {
        $values  = array_values($params);
        $missing = [];

        for ($i = 0; $i < (int) $this->variable_count; $i++) {
            if (trim((string) ($values[$i] ?? '')) === '') {
                $missing[] = $i + 1;
            }
        }

        return $missing;
    }

    /** "{{1}}, {{3}}" — for validation messages. */
    public static function describeVariables(array $indexes): string
    {
        return implode(', ', array_map(fn ($i) => '{{' . $i . '}}', $indexes));
    }

    /**
     * Render the body with supplied values, for the chat preview bubble.
     */
    public function render(array $params = []): string
    {
        $text = (string) $this->body_text;

        foreach (array_values($params) as $index => $value) {
            $text = str_replace('{{' . ($index + 1) . '}}', (string) $value, $text);
        }

        return $text;
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'APPROVED' => 'success',
            'PENDING', 'IN_APPEAL' => 'warning',
            'REJECTED', 'DISABLED' => 'danger',
            'PAUSED'  => 'secondary',
            default   => 'secondary',
        };
    }
}
