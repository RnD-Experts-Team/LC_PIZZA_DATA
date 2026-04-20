<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_debriefs', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('user_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['store_id', 'employee_id']);

            $table->dropIndex(['store_id', 'employee_name']);
            $table->dropColumn('employee_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_debriefs', function (Blueprint $table) {
            $table->string('employee_name')->nullable()->after('user_id');
            $table->index(['store_id', 'employee_name']);

            $table->dropIndex(['store_id', 'employee_id']);
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
