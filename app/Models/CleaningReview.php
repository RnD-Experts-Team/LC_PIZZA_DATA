<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CleaningReview extends Model
{
    protected $fillable = [
        'store_number',
        'review_place',
        'score',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
