<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three ingredients the Dough & Sauce module tracks.
 *
 * Lives on the application's own database, not `aggregation`. Everything in
 * aggregation is output: rows a scheduled job computes from operational data and
 * rebuilds on a schedule. This table is input — three rows a human maintains, the
 * same kind of thing as `goal_metrics` or `tags`. Keeping the two apart is what
 * makes it safe to say that nothing outside the aggregation pipeline writes to
 * the aggregation database.
 *
 * The plan query therefore cannot join this to daily_item_summary in one
 * statement, and does not try to: it reads the three reference tables and the
 * summary separately and multiplies in PHP. See DoughSaucePlanService. The three
 * connections point at separate schemas with their own host settings, so a
 * cross-database join could work locally and fail in production.
 *
 * Creates a new table only — nothing existing is read, altered or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dough_sauce_ingredients', function (Blueprint $table) {
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
        Schema::dropIfExists('dough_sauce_ingredients');
    }
};
