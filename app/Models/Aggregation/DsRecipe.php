<?php

namespace App\Models\Aggregation;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one ingredient one menu item consumes, over a date range.
 *
 * Rows are never updated in place. A change closes the current row with an
 * `effective_to` and opens a new one — which is what makes opening a past week
 * give the number that week was actually planned with.
 */
class DsRecipe extends AggregationModel
{
    protected $table = 'ds_recipes';

    protected $fillable = [
        'ds_menu_item_id', 'ds_ingredient_id', 'qty',
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
        return $this->belongsTo(DsMenuItem::class, 'ds_menu_item_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(DsIngredient::class, 'ds_ingredient_id');
    }

    /** Rows in force on a given business date. */
    public function scopeEffectiveOn($query, CarbonInterface|string $date)
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
