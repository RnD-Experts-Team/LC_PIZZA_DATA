<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('key_store_rule_times', function (Blueprint $table) {
            $table->id();

            $table->foreignId('key_store_rule_id')
                ->constrained('key_store_rules')
                ->cascadeOnDelete();

            $table->time('due_time');

            $table->timestamp('last_notified_at')->nullable();
            $table->date('last_notified_for_date')->nullable();

            $table->timestamps();

            $table->unique(['key_store_rule_id', 'due_time']);
            $table->index('due_time');
            $table->index('last_notified_for_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('key_store_rule_times');
    }
};