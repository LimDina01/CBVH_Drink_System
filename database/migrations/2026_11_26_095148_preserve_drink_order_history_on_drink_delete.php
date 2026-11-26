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
        Schema::table('drink_order_items', function (Blueprint $table) {
            $table->string('drink_name_snapshot')->nullable()->after('drink_item_id');
            $table->string('drink_category_snapshot')->nullable()->after('drink_name_snapshot');
        });

        // Backfill snapshots for existing historical order items
        DB::table('drink_order_items')
            ->join('drink_items', 'drink_order_items.drink_item_id', '=', 'drink_items.id')
            ->select('drink_order_items.id', 'drink_items.name', 'drink_items.category')
            ->orderBy('drink_order_items.id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('drink_order_items')
                        ->where('id', $row->id)
                        ->update([
                            'drink_name_snapshot' => $row->name,
                            'drink_category_snapshot' => $row->category,
                        ]);
                }
            }, 'drink_order_items.id', 'id');

        Schema::table('drink_order_items', function (Blueprint $table) {
            $table->dropForeign(['drink_item_id']);
            $table->unsignedBigInteger('drink_item_id')->nullable()->change();
            $table->foreign('drink_item_id')
                ->references('id')
                ->on('drink_items')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drink_order_items', function (Blueprint $table) {
            $table->dropForeign(['drink_item_id']);
            $table->unsignedBigInteger('drink_item_id')->nullable(false)->change();
            $table->foreign('drink_item_id')
                ->references('id')
                ->on('drink_items')
                ->cascadeOnDelete();
            $table->dropColumn(['drink_name_snapshot', 'drink_category_snapshot']);
        });
    }
};
