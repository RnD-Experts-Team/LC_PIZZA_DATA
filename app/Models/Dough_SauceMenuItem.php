<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A menu item a recipe can attach to.
 *
 * `item_id` is what lines this up with daily_item_summary.item_id — never the
 * name, which is free text and would break the match the first time someone adds
 * a trademark symbol.
 */
class Dough_SauceMenuItem extends Model
{
    protected $table = 'dough_sauce_menu_items';

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
        return $this->hasMany(Dough_SauceRecipe::class, 'dough_sauce_menu_item_id');
    }

    public function scopeForItem($query, string $itemId)
    {
        return $query->where('item_id', $itemId);
    }
}
