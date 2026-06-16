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
        Schema::create('transfer_in_outs', function (Blueprint $table) {
            $table->id();
            $table->string('major_category');
            $table->date('date');
            $table->string('to_store_number');
            $table->string('from_store_number');
            $table->decimal('base_unit_per_report_unit', 15, 4);
            $table->string('ing_id');
            $table->string('ing_des');
            $table->decimal('quantity', 15, 4);
            $table->string('unit');
            $table->decimal('total_cost', 15, 4);
            $table->boolean('is_posted')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_in_outs');
    }
};
