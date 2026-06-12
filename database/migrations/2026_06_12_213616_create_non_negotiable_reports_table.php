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
        Schema::create('non_negotiable_reports', function (Blueprint $table) {
            $table->id();
            $table->string('store_number')->nullable();
            $table->string('action')->nullable();
            $table->date('date')->nullable();
            $table->time('time')->nullable();
            $table->date('date_two')->nullable();
            $table->time('time_two')->nullable();
            $table->longText('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('non_negotiable_reports');
    }
};
