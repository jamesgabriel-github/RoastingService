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
        Schema::table('booking_items', function (Blueprint $table) {
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
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('weighed_at')->nullable();
            $table->timestamp('cooking_started_at')->nullable();
            $table->timestamp('est_ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('reject_reason')->nullable();
        });

        Schema::table('booking_status_logs', function (Blueprint $table) {
            $table->foreignId('booking_item_id')->constrained('booking_items')->cascadeOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['confirmed_by']);
            $table->dropColumn([
                'status',
                'approved_at',
                'approved_by',
                'confirmed_at',
                'confirmed_by',
                'weighed_at',
                'cooking_started_at',
                'est_ready_at',
                'completed_at',
                'reject_reason',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
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
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('weighed_at')->nullable();
            $table->timestamp('cooking_started_at')->nullable();
            $table->timestamp('est_ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('reject_reason')->nullable();
        });

        Schema::table('booking_status_logs', function (Blueprint $table) {
            $table->dropForeign(['booking_item_id']);
            $table->dropColumn('booking_item_id');
        });

        Schema::table('booking_items', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['confirmed_by']);
            $table->dropColumn([
                'status',
                'approved_at',
                'approved_by',
                'confirmed_at',
                'confirmed_by',
                'weighed_at',
                'cooking_started_at',
                'est_ready_at',
                'completed_at',
                'reject_reason',
            ]);
        });
    }
};
