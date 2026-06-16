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
        Schema::create('inventory_orders', function (Blueprint $table) {
            $table->id();
            $table->date('delivery_date');
            $table->string('invoice_no');
            $table->string('store_number');
            $table->string('vendor_number');
            $table->string('vendor_name');
            $table->decimal('asn_total', 15, 4);
            $table->decimal('invoice_total', 15, 4);
            $table->boolean('is_manual_asn')->default(false);
            $table->boolean('is_asn_received')->default(false);
            $table->boolean('is_ordered')->default(false);
            $table->string('order_status');
            $table->boolean('is_posted')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_orders');
    }
};
