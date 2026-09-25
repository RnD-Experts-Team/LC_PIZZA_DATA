<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of each ingredient a menu item consumes — the bill of materials.
 *
 * This is the table that replaces a column of markers in a spreadsheet. Two of
 * the workbook's numbers were not in the sheet at all but buried in its formula:
 * Crazy Puffs were divided by 2 and Deep Dish sauce multiplied by 2. Here they
 * are just `qty` values, visible and editable without touching code — hence
 * decimal, not integer.
 *
 * Reference table on the `aggregation` connection — see ds_ingredients for why.
 * Not partitioned: ~82 rows.
 *
 * Creates a new table only.
 */
return new class extends Migration
{
    protected $connection = 'aggregation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('ds_recipes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ds_menu_item_id')->constrained('ds_menu_items')->cascadeOnDelete();
            $table->foreignId('ds_ingredient_id')->constrained('ds_ingredients')->cascadeOnDelete();

            // Fractions are real: half a 10 OZ ball for Crazy Puffs, two sauce
            // portions for a Deep Dish.
            $table->decimal('qty', 8, 4);

            // ── Effective dating ──
            // A correctness requirement, not an optimisation. A store manager can
            // open a past date, and that day has to be computed with the recipe as
            // it was on that day. Editing closes the old row and opens a new one;
            // it never overwrites. Without this, changing one recipe today would
            // quietly rewrite every plan and every score that was ever built on it.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();   // null = currently in force

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // One row per item + ingredient + start date. The start date is part of
            // the key precisely because a second row for the same pair is how a
            // change is recorded.
            $table->unique(['ds_menu_item_id', 'ds_ingredient_id', 'effective_from'], 'ds_recipes_unique');

            // The plan query filters on the date window for every joined row.
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('ds_recipes');
    }
};
