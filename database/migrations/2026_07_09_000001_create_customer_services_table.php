<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_services', function (Blueprint $table) {
            $table->id();
            $table->string('store_number');
            $table->time('lobby_in')->nullable();
            $table->time('lobby_out')->nullable();
            $table->time('drive_thru_in')->nullable();
            $table->time('drive_thru_out')->nullable();
            $table->decimal('guest_service', 8, 2)->nullable();
            $table->date('date');
            $table->timestamps();

            $table->index('store_number');
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_services');
    }
};
