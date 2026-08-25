<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    protected $connection = 'archive';

    public function up(): void
    {
        // Create table with same structure as hot table
        Schema::connection($this->connection)->create('detail_transactions_archive', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement();

            // Core identifiers
            $table->string('franchise_store', 20)->index();
            $table->date('business_date')->index();
            $table->string('order_id', 20)->nullable();

            // Timestamps
            $table->dateTime('date_time_placed')->nullable();
            $table->dateTime('date_time_fulfilled')->nullable();
            $table->dateTime('transaction_date_time')->nullable();

            // Tender/payment info
            $table->decimal('tendered_amount', 15, 4)->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('sub_payment_method', 50)->nullable();
            $table->string('refund', 20)->nullable();
            $table->string('terminal_payment_made', 20)->nullable();
            $table->string('card_last4', 10)->nullable();
            $table->string('saf_transaction', 10)->nullable();

            // Employee info
            $table->string('employee')->nullable();
            $table->string('override_approval_employee')->nullable();

            // Order methods
            $table->string('order_placed_method', 50)->nullable();
            $table->string('order_fulfilled_method', 50)->nullable();

            // Purchase order / entity info
            $table->string('po_number')->nullable();
            $table->string('po_entity_name')->nullable();

            // User info
            $table->string('user_id', 50)->nullable();

            // Laravel timestamps
            $table->timestamps();

            $table->primary(['id', 'business_date']);
            // Indexes (fewer than hot table since archive is read-heavy)
            $table->index(['franchise_store', 'business_date']);
        });

        // Add monthly partitioning with compression
        $this->addMonthlyPartitions('detail_transactions_archive');
    }

    protected function addMonthlyPartitions($tableName): void
    {
        // Generate partitions from 2020-01 to current month + 3 months future
        $startDate = Carbon::create(2020, 1, 1);
        $endDate = Carbon::now()->addMonths(3)->endOfMonth();

        $partitions = [];
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $partName = 'p' . $current->format('Ym');
            $nextMonth = $current->copy()->addMonth();
            $partValue = $nextMonth->year * 100 + $nextMonth->month;

            $partitions[] = "PARTITION {$partName} VALUES LESS THAN ({$partValue})";
            $current->addMonth();
        }

        $partitions[] = "PARTITION p_future VALUES LESS THAN MAXVALUE";

        $partitionSql = "ALTER TABLE {$tableName}
            PARTITION BY RANGE (YEAR(business_date) * 100 + MONTH(business_date)) ("
            . implode(", ", $partitions) . ")";

        DB::connection($this->connection)->statement($partitionSql);

        // Enable compression for archive table
        if (config('features.archive_compression_enabled', true)) {
            DB::connection($this->connection)->statement(
                "ALTER TABLE {$tableName} ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8"
            );
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('detail_transactions_archive');
    }
};
