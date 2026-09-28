<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one ingredient one menu item consumes, over a date range.
 *
 * Rows are never updated in place. A change closes the current row with an
 * `effective_to` and opens a new one — which is what makes opening a past week
 * give the number that week was actually planned with.
 */
class Dough_SauceRecipe extends Model
{
    protected $table = 'dough_sauce_recipes';

    protected $fillable = [
        'dough_sauce_menu_item_id', 'dough_sauce_ingredient_id', 'qty',
        'effective_from', 'effective_to', 'created_by',
    ];

    protected $casts = [
        'qty'            => 'decimal:4',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(Dough_SauceMenuItem::class, 'dough_sauce_menu_item_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Dough_SauceIngredient::class, 'dough_sauce_ingredient_id');
    }

    /** Rows in force on a given business date. */
    public function scopeEffectiveOn($query, CarbonInterface|string $date)
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /** Rows in force at any point inside a window — what the plan query needs. */
    public function scopeEffectiveWithin($query, string $from, string $to)
    {
        return $query->whereDate('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from));
    }
}
