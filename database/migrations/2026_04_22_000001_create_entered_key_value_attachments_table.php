<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('entered_key_value_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('entered_key_value_id')
                ->constrained('entered_key_values')
                ->cascadeOnDelete();

            $table->string('file_path');
            $table->string('disk')->default('public');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->timestamps();

            $table->index('entered_key_value_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entered_key_value_attachments');
    }
};
