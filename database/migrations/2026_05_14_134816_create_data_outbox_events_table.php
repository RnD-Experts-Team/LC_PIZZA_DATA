<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('data_outbox_events', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('subject', 190)->index();
            $table->string('type', 190)->index();

            $table->json('payload');

            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamp('published_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_outbox_events');
    }
};