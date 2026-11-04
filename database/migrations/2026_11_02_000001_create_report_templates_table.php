<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved report builder definitions. A template is private to its owner or
 * shared with the whole organization; only the owner may change or delete it.
 * Deleting is a hard delete and takes the template's runs with it.
 *
 * Creates a new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->index();
            $table->string('name', 190);
            $table->text('description')->nullable();
            // Definition JSON v1, checked by ReportDefinitionValidator on every save.
            $table->json('definition');
            $table->enum('visibility', ['private', 'org'])->default('private')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};
