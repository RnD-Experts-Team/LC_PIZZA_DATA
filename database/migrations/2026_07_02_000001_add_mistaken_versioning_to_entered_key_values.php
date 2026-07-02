<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('entered_key_values', function (Blueprint $table) {
            $table->boolean('is_mistaken')->default(false)->after('note');
            $table->timestamp('superseded_at')->nullable()->after('is_mistaken');

            $table->foreignId('corrected_from_id')->nullable()->after('superseded_at')
                ->constrained('entered_key_values')->nullOnDelete();

            $table->index(['key_id', 'store_id', 'entry_date', 'is_mistaken'], 'ekv_identity_mistaken_idx');
        });
    }

    public function down(): void
    {
        Schema::table('entered_key_values', function (Blueprint $table) {
            $table->dropForeign(['corrected_from_id']);
            $table->dropIndex('ekv_identity_mistaken_idx');
            $table->dropColumn(['is_mistaken', 'superseded_at', 'corrected_from_id']);
        });
    }
};
