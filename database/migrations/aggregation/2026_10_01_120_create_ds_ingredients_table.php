<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three ingredients the Dough & Sauce module tracks.
 *
 * Lives on the `aggregation` connection because the plan query joins it to
 * daily_item_summary, and separate databases cannot be joined in one statement.
 * Putting it on `operational` would force two queries and a multiplication in
 * PHP over thousands of rows.
 *
 * Unlike every other table in this folder it is NOT partitioned and has no
 * composite primary key: those exist on the *_summary tables because their rows
 * are dated and grow forever. This one holds three rows and never grows.
 *
 * Creates a new table only — nothing existing is read, altered or dropped.
 */
return new class extends Migration
{
    protected $connection = 'aggregation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('ds_ingredients', function (Blueprint $table) {
            $table->id();

            // Stable code the API speaks. AuditApp stores this string on its plan
            // rows, so renaming one would orphan its history — the display name is
            // what changes, never this.
            $table->string('key', 40)->unique();

            $table->string('name', 120);
            $table->string('unit', 30);

            // Sales unit -> the unit the inventory system counts:
            //   pizzas         / 1  = dough balls
            //   bread portions / 12 = dough balls
            //   sauce portions / 80 = containers
            $table->decimal('divisor', 8, 3);

            // The item's ultimatrix_id in the inventory project, so a consumer can
            // line our figure up against what was actually counted.
            $table->string('inventory_ref', 40)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('ds_ingredients');
    }
};
