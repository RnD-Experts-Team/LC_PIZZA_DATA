<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoalMetric extends Model
{
    protected $fillable = ['name'];

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }
}
