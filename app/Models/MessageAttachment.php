<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'path',
        'disk',
        'provider_media_id',
        'source_url',
        'mime',
        'type',
        'filename',
        'caption',
        'size',
        'sha256',
        'download_status',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Media is stored on a private disk, so it is always served through the
     * authenticated media route rather than a direct storage URL.
     */
    public function url(): string
    {
        return route('chat.media', $this);
    }

    public function isImage(): bool
    {
        return $this->type === 'image' || str_starts_with((string) $this->mime, 'image/');
    }

    public function isVideo(): bool
    {
        return $this->type === 'video' || str_starts_with((string) $this->mime, 'video/');
    }

    public function isAudio(): bool
    {
        return $this->type === 'audio' || str_starts_with((string) $this->mime, 'audio/');
    }

    public function exists(): bool
    {
        return $this->path && Storage::disk($this->disk ?: 'local')->exists($this->path);
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size;

        if ($bytes <= 0) {
            return '';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1) . ' ' . $units[$power];
    }

    public function icon(): string
    {
        return match (true) {
            $this->isImage() => 'bi-image',
            $this->isVideo() => 'bi-camera-video',
            $this->isAudio() => 'bi-mic',
            str_contains((string) $this->mime, 'pdf') => 'bi-file-earmark-pdf',
            str_contains((string) $this->mime, 'sheet'), str_contains((string) $this->mime, 'excel') => 'bi-file-earmark-spreadsheet',
            str_contains((string) $this->mime, 'word') => 'bi-file-earmark-word',
            default => 'bi-file-earmark',
        };
    }
}
