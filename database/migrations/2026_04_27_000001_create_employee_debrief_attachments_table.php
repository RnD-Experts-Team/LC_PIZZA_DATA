<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_debrief_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_debrief_id')
                ->constrained('employee_debriefs')
                ->cascadeOnDelete();

            $table->string('file_path');
            $table->string('disk')->default('public');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->timestamps();

            $table->index('employee_debrief_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_debrief_attachments');
    }
};
