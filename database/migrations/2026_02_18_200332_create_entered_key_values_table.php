<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('entered_key_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('key_id')
                ->constrained('entered_keys')
                ->cascadeOnDelete();

            $table->string('store_id');
            $table->date('entry_date');

            $table->text('value_text')->nullable();
            $table->decimal('value_number', 15, 4)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->json('value_json')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'entry_date']);
            $table->index(['key_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entered_key_values');
    }
};
