<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'id',
        'first_name',
        'middle_name',
        'last_name',
        'store_id',
        'active'
    ];

    protected $casts = [
        'id' => 'integer',
        'active' => 'boolean'
    ];

    public function debriefs(): HasMany
    {
        return $this->hasMany(EmployeeDebrief::class, 'employee_id');
    }
}
