<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_order')->nullable()->after('source_type');
        });

        DB::table('bookings')->where('source_type', 'shop_supplied')->update(['is_order' => true]);
        DB::table('bookings')->where('source_type', 'customer_supplied')->update(['is_order' => false]);

        DB::statement('ALTER TABLE bookings ALTER COLUMN is_order SET NOT NULL');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('source_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('source_type', ['customer_supplied', 'shop_supplied'])->nullable()->after('guest_phone');
        });

        DB::table('bookings')->where('is_order', true)->update(['source_type' => 'shop_supplied']);
        DB::table('bookings')->where('is_order', false)->update(['source_type' => 'customer_supplied']);

        DB::statement('ALTER TABLE bookings ALTER COLUMN source_type SET NOT NULL');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('is_order');
        });
    }
};
