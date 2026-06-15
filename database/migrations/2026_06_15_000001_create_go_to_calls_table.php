<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('go_to_calls', function (Blueprint $table) {
            $table->id();
            $table->string('store_number');
            $table->dateTime('datetime');
            $table->unsignedInteger('duration'); // duration in seconds
            $table->string('call_result');
            $table->string('from');
            $table->enum('status', ['is_store', 'is_store_manager', 'is_call_center', 'is_missed']);
            $table->timestamps();

            // Indexes
            $table->index('store_number');
            $table->index('datetime');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('go_to_calls');
    }
};
