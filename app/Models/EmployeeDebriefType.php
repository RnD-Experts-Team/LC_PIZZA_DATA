<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeDebriefType extends Model
{
    protected $fillable = [
        'slug',
        'label',
    ];

    public function debriefs(): HasMany
    {
        return $this->hasMany(EmployeeDebrief::class, 'type_id');
    }
}
