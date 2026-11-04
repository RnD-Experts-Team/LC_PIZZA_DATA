<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One execution of a report template: the definition as it was, the period it
 * covered and the files it wrote under storage/app/private/reports/. Rows and
 * files are pruned once `expires_at` (7 days) has passed.
 *
 * Creates a new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('template_id')->constrained('report_templates')->cascadeOnDelete();
            // Gets its foreign key with report_schedules.
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->unsignedBigInteger('requested_by_user_id');
            // The template's definition when the run was asked for.
            $table->json('definition');
            $table->date('period_from');
            $table->date('period_to');
            $table->enum('format', ['csv', 'xlsx']);
            $table->enum('status', ['queued', 'running', 'done', 'failed'])->default('queued');
            $table->unsignedBigInteger('row_count')->nullable();
            $table->string('csv_path')->nullable();
            $table->string('xlsx_path')->nullable();
            // Bytes of the file in the run's format.
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();
            // columns, truncated, skipped_stores.
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            // Moved on by a running run every 10,000 rows and at each phase; a run
            // still `running` without one for 30 minutes is failed (reports:dispatch-due).
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            // A user's runs, and their one active run.
            $table->index(['requested_by_user_id', 'status']);
            // Running runs gone quiet.
            $table->index(['status', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
