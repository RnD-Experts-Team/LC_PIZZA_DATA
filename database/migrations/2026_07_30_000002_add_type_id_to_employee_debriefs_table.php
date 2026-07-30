<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::table('employee_debriefs', function (Blueprint $table) {
            $table->unsignedBigInteger('type_id')->nullable()->after('employee_id');
            $table->foreign('type_id')->references('id')->on('employee_debrief_types')->cascadeOnUpdate()->nullOnDelete();
            $table->index('type_id');
        });
    }

    public function down(): void
    {
        Schema::table('employee_debriefs', function (Blueprint $table) {
            $table->dropIndex(['type_id']);
            $table->dropForeign(['type_id']);
            $table->dropColumn('type_id');
        });
    }
};
