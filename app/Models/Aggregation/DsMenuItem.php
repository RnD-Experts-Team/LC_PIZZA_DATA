<?php

namespace App\Models\Aggregation;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A menu item a recipe can attach to.
 *
 * `item_id` is the join key against daily_item_summary.item_id — never the name,
 * which is free text and would break the join the first time someone adds a
 * trademark symbol.
 */
class DsMenuItem extends AggregationModel
{
    protected $table = 'ds_menu_items';

    protected $fillable = [
        'item_id', 'menu_item_name', 'menu_item_account', 'active',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function recipes(): HasMany
    {
        return $this->hasMany(DsRecipe::class, 'ds_menu_item_id');
    }

    public function scopeForItem($query, string $itemId)
    {
        return $query->where('item_id', $itemId);
    }
}
