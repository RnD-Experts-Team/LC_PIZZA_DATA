<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeDebrief extends Model
{

    protected $fillable = [
        'store_id',
        'user_id',
        'employee_id',
        'note',
        'date'
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'date' => 'date'
    ];

    protected $with = ['attachments'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmployeeDebriefAttachment::class, 'employee_debrief_id');
    }

}