<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order-level figures per store and day that otherwise need a scan of the raw
 * orders for every report: channel customers and the website/mobile split,
 * special-items HnR, and upselling (units sold with a pizza). Built in the
 * daily stage from that store-day's raw orders; Business Reports sums these
 * rows instead of reading a year of orders.
 */
return new class extends Migration
{
    protected $connection = 'aggregation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('daily_order_extras', function (Blueprint $table) {
            $table->id();
            $table->string('franchise_store', 20);
            $table->date('business_date');

            // Customers by how the order was placed.
            $table->unsignedInteger('customers_total')->default(0);
            $table->unsignedInteger('customers_phone')->default(0);
            $table->unsignedInteger('customers_call_center')->default(0);
            $table->unsignedInteger('customers_drive_thru')->default(0);
            $table->unsignedInteger('customers_doordash')->default(0);
            $table->unsignedInteger('customers_ubereats')->default(0);
            $table->unsignedInteger('customers_grubhub')->default(0);

            // Website / mobile: in store vs delivery (sales and customers).
            $table->decimal('website_in_store', 12, 2)->default(0);
            $table->decimal('website_delivery', 12, 2)->default(0);
            $table->decimal('mobile_in_store', 12, 2)->default(0);
            $table->decimal('mobile_delivery', 12, 2)->default(0);
            $table->unsignedInteger('website_in_store_customers')->default(0);
            $table->unsignedInteger('website_delivery_customers')->default(0);
            $table->unsignedInteger('mobile_in_store_customers')->default(0);
            $table->unsignedInteger('mobile_delivery_customers')->default(0);

            // HnR on orders with a special item.
            $table->unsignedInteger('special_hnr_transactions')->default(0);
            $table->unsignedInteger('special_hnr_broken_promises')->default(0);

            // Upselling, in-store orders: units sold with a pizza, pizza units, EMB units.
            $table->unsignedInteger('upsell_crazy_bread')->default(0);
            $table->unsignedInteger('upsell_cookies')->default(0);
            $table->unsignedInteger('upsell_sauce')->default(0);
            $table->unsignedInteger('upsell_wings')->default(0);
            $table->unsignedInteger('upsell_beverages')->default(0);
            $table->unsignedInteger('upsell_crazy_puffs')->default(0);
            $table->unsignedInteger('upsell_bev_20oz')->default(0);
            $table->unsignedInteger('upsell_bev_2l')->default(0);
            $table->unsignedInteger('upsell_italian_cheese_bread')->default(0);
            $table->unsignedInteger('upsell_pizza_base')->default(0);
            $table->unsignedInteger('upsell_emb_cheese')->default(0);
            $table->unsignedInteger('upsell_emb_pepperoni')->default(0);

            $table->timestamps();

            $table->unique(['franchise_store', 'business_date']);
            $table->index('business_date');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('daily_order_extras');
    }
};
