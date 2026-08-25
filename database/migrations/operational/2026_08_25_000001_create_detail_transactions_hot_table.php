<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'operational';

    public function up(): void
    {
        Schema::connection($this->connection)->create('detail_transactions_hot', function (Blueprint $table) {
            $table->id();

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

            // Indexes
            $table->index(['franchise_store', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('detail_transactions_hot');
    }
};
