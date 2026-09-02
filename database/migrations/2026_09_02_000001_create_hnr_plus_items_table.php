<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hnr_plus_items', function (Blueprint $table) {
            $table->id();
            $table->string('store_number');
            $table->date('week_start');
            $table->date('week_end');
            $table->string('item_id');
            $table->string('item_name');
            $table->integer('made');
            $table->integer('sold');
            $table->integer('voided');
            $table->integer('wasted');
            $table->integer('variance');
            $table->integer('no_inventory_available');
            $table->timestamps();

            $table->index(['store_number', 'week_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hnr_plus_items');
    }
};
