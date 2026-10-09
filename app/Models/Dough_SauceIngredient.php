<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the three ingredients the Dough & Sauce module tracks.
 *
 * `divisor` converts a sales quantity into the unit the inventory system counts:
 * pizzas / 1 = balls, bread / 12 = balls, sauce portions / 80 = containers.
 *
 * On the default connection, like every other model in this namespace. It is
 * reference data a human maintains, not output of the aggregation pipeline.
 */
class Dough_SauceIngredient extends Model
{
    protected $table = 'dough_sauce_ingredients';

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
        return $this->hasMany(Dough_SauceRecipe::class, 'dough_sauce_ingredient_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
