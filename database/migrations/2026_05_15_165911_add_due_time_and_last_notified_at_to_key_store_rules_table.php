<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('key_store_rules', function (Blueprint $table) {
            $table->time('due_time')->after('year_month');
            $table->timestamp('last_notified_at')->nullable()->after('due_time');

            $table->index('due_time');
        });
    }

    public function down(): void
    {
        Schema::table('key_store_rules', function (Blueprint $table) {
            $table->dropIndex(['due_time']);

            $table->dropColumn([
                'due_time',
                'last_notified_at',
            ]);
        });
    }
};