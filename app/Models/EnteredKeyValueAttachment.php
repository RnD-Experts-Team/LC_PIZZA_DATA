<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EnteredKeyValueAttachment extends Model
{
    protected $fillable = [
        'entered_key_value_id',
        'file_path',
        'disk',
        'original_name',
        'mime_type',
        'size',
    ];

    protected $appends = ['attachment_url'];

    public function enteredKeyValue(): BelongsTo
    {
        return $this->belongsTo(EnteredKeyValue::class, 'entered_key_value_id');
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->getAttachmentUrl();
    }

    public function getAttachmentUrl(): ?string
    {
        if (empty($this->file_path)) {
            return null;
        }

        if (str_starts_with($this->file_path, 'http://') || str_starts_with($this->file_path, 'https://')) {
            return $this->file_path;
        }

        $filesystem = Storage::disk($this->disk ?: 'public');

        if (method_exists($filesystem, 'url')) {
            return $filesystem->url($this->file_path);
        }

        return null;
    }
}
