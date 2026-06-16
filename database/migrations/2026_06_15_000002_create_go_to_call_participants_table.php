<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('go_to_call_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('go_to_call_id')->constrained('go_to_calls')->cascadeOnDelete();
            $table->string('participant');
            $table->timestamps();

            // Indexes
            $table->index('go_to_call_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('go_to_call_participants');
    }
};
