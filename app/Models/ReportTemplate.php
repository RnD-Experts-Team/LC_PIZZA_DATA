<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved report builder definition, private to its owner or shared with the
 * organization (`org`).
 */
class ReportTemplate extends Model
{
    protected $fillable = [
        'owner_user_id',
        'name',
        'description',
        'definition',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'owner_user_id' => 'integer',
            'definition' => 'array',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class, 'template_id');
    }

    /** The user's own templates and every org-wide one. */
    public function scopeReadableBy(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('owner_user_id', $userId)->orWhere('visibility', 'org'));
    }
}
