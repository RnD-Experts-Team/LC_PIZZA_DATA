<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('employee_debrief_types', function (Blueprint $table) {

            $table->id();

            $table->string('slug')->unique();

            $table->string('label');

            $table->timestamps();
        });

        DB::table('employee_debrief_types')->insert([
            [
                'slug' => 'call_off',
                'label' => 'Call Off',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'no_call_no_show',
                'label' => 'No Call No Show',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_debrief_types');
    }
};
