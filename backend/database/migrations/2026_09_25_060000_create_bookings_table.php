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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('guest_phone')->nullable();
            $table->enum('source_type', ['customer_supplied', 'shop_supplied']);
            $table->enum('fulfillment', ['pickup', 'delivery']);
            $table->string('delivery_address', 500)->nullable();
            $table->decimal('shipping_fee', 10, 2)->default(0);
            $table->enum('status', [
                'pending_review',
                'pending_confirmation',
                'approved',
                'confirmed',
                'cooking',
                'ready',
                'out_for_delivery',
                'completed',
                'rejected',
                'no_show',
                'cancelled',
            ])->nullable();
            $table->timestamp('preferred_dropoff_at')->nullable();
            $table->timestamp('dropoff_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('weighed_at')->nullable();
            $table->timestamp('cooking_started_at')->nullable();
            $table->timestamp('est_ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('estimated_total', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
