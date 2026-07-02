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
        Schema::create('cleaning_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('store_number');
            $table->string('review_place');
            $table->enum('score', ['Pass', 'Fail', 'Auto Fail']);
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
        Schema::dropIfExists('cleaning_reviews');
    }
};
