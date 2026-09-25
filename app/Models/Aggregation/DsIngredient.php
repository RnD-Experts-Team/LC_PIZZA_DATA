<?php

namespace App\Models\Aggregation;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the three ingredients the Dough & Sauce module tracks.
 *
 * `divisor` converts a sales quantity into the unit the inventory system counts:
 * pizzas / 1 = balls, bread / 12 = balls, sauce portions / 80 = containers.
 */
class DsIngredient extends AggregationModel
{
    protected $table = 'ds_ingredients';

    protected $fillable = [
        'key', 'name', 'unit', 'divisor', 'inventory_ref', 'sort_order', 'active',
    ];

    protected $casts = [
        'divisor'    => 'decimal:3',
        'sort_order' => 'integer',
        'active'     => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function recipes(): HasMany
    {
        return $this->hasMany(DsRecipe::class, 'ds_ingredient_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
