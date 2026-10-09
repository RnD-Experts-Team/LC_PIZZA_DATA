<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The menu items the Dough & Sauce recipes attach to.
 *
 * A thin catalogue, not a copy of the menu: it exists so a recipe has something
 * stable to hang off, and so an item that sells but has no recipe can be spotted.
 *
 * Reference table on the application database — see dough_sauce_ingredients for
 * why it is not in `aggregation`.
 *
 * Creates a new table only — daily_item_summary is read by the plan query but
 * never altered by this module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dough_sauce_menu_items', function (Blueprint $table) {
            $table->id();

            // Matches daily_item_summary.item_id exactly: varchar(20), not an
            // integer. NEVER match on menu_item_name — it is free text, so
            // "Crazy Bread" becoming "Crazy Bread(R)" would break the lookup
            // silently and turn that item's dough into zero with no warning.
            $table->string('item_id', 20)->unique();

            $table->string('menu_item_name', 255);

            // Pizza | HNR | Bread | Beverage | ... Drives which unmapped items are
            // worth warning about: a drink with no recipe is correct, a pizza with
            // no recipe is a hole in every plan.
            $table->string('menu_item_account', 60);

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('menu_item_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dough_sauce_menu_items');
    }
};
