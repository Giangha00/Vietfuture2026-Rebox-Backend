<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->json('items');
            $table->decimal('total_amount', 14, 2);
            $table->decimal('platform_fee', 14, 2)->default(0);
            $table->decimal('seller_payout', 14, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('note', 500)->default('');
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('pickup_station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->json('delivery_address')->nullable();
            $table->json('pickup_address')->nullable();
            $table->string('status')->default('pending_payment')->index();
            $table->enum('payment_status', ['unpaid', 'pending', 'paid', 'refunded', 'released'])->default('unpaid')->index();
            $table->enum('escrow_status', ['none', 'held', 'released', 'refunded'])->default('none')->index();
            $table->string('paypal_order_id')->default('')->index();
            $table->string('paypal_capture_id')->default('');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('shipper_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('estimated_pickup_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('buyer_confirmed_at')->nullable();
            $table->timestamp('auto_complete_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->default('');
            $table->json('timeline')->nullable();
            $table->json('dispute')->nullable();
            $table->timestamps();
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });
        Schema::dropIfExists('orders');
    }
};
