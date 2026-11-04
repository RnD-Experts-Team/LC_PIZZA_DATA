<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report template run on a schedule for its owner and delivered to the
 * users listed in `recipients`. `run_token` is the owner's revocable,
 * least-privilege pizzasys token, minted for this schedule and kept encrypted
 * until `token_expires_at`.
 * Gives report_runs.schedule_id its foreign key (null when the schedule goes).
 *
 * Creates a new table and adds one foreign key to report_runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('report_templates')->cascadeOnDelete();
            $table->unsignedBigInteger('owner_user_id')->index();
            $table->enum('frequency', ['daily', 'weekly', 'monthly']);
            // Local time in `timezone`.
            $table->time('run_time');
            // Weekly: 0 = Sunday … 6 = Saturday.
            $table->unsignedTinyInteger('weekday')->nullable();
            // Monthly: 29-31 fall on the last day of a shorter month.
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('timezone', 64)->default('America/New_York');
            // {users: [ids]}
            $table->json('recipients');
            $table->enum('format', ['csv', 'xlsx']);
            $table->enum('delivery', ['link', 'attachment', 'both']);
            $table->text('run_token')->nullable();
            // When pizzasys lets run_token lapse; the dispatcher pauses the schedule a week before.
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('report_runs', function (Blueprint $table) {
            $table->foreign('schedule_id')->references('id')->on('report_schedules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('report_runs', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
        });
        Schema::dropIfExists('report_schedules');
    }
};
