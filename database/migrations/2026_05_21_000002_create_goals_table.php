<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_metric_id')->constrained('goal_metrics')->cascadeOnDelete();
            $table->string('store_id');
            $table->date('week_start_date');
            $table->date('week_end_date');
            $table->decimal('goal', 15, 4);
            $table->timestamps();

            $table->unique(['goal_metric_id', 'store_id', 'week_start_date']);
            $table->index(['store_id', 'week_start_date', 'week_end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
