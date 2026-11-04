<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Reports (and the per-store dashboard) read these tables by store
 * and date; their create migrations defined no index at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_orders', function (Blueprint $table) {
            $table->index(['store_number', 'delivery_date']);
        });

        Schema::table('transfer_in_outs', function (Blueprint $table) {
            $table->index('date');
        });

        Schema::table('non_negotiable_reports', function (Blueprint $table) {
            $table->index(['store_number', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_orders', function (Blueprint $table) {
            $table->dropIndex(['store_number', 'delivery_date']);
        });

        Schema::table('transfer_in_outs', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });

        Schema::table('non_negotiable_reports', function (Blueprint $table) {
            $table->dropIndex(['store_number', 'date']);
        });
    }
};
