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
 * Reference table on the `aggregation` connection — see ds_ingredients for why.
 * Not partitioned: ~93 rows, and it grows with the menu, not with time.
 *
 * Creates a new table only — daily_item_summary is read by the plan query but
 * never altered by this module.
 */
return new class extends Migration
{
    protected $connection = 'aggregation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('ds_menu_items', function (Blueprint $table) {
            $table->id();

            // Joins daily_item_summary.item_id, and matches its type exactly:
            // varchar(20), not an integer. NEVER join on menu_item_name — it is
            // free text, so "Crazy Bread" becoming "Crazy Bread(R)" would break the
            // join silently and turn that item's dough into zero with no warning.
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
        Schema::connection($this->connection)->dropIfExists('ds_menu_items');
    }
};
