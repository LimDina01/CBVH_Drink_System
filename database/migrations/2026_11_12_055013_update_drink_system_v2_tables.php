<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('is_hq')->default(false)->after('code');
        });

        // Mark HQ branch as HQ
        DB::table('branches')->where('code', 'HQ')->update(['is_hq' => true]);

        Schema::table('drink_orders', function (Blueprint $table) {
            $table->string('order_type')->default('instant')->after('room_id'); // instant, meeting, extend
            $table->foreignId('parent_order_id')->nullable()->after('order_type')->constrained('drink_orders')->nullOnDelete();
            $table->string('extend_reason')->nullable()->after('parent_order_id'); // spilled, extra_guests, additional
            $table->string('attachment_path')->nullable()->after('notes');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->integer('estimated_wait_minutes')->nullable()->after('delivery_time');
            $table->dateTime('in_delivery_at')->nullable()->after('ready_at');
            $table->boolean('auto_completed')->default(false)->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('drink_orders', function (Blueprint $table) {
            $table->dropForeign(['parent_order_id']);
            $table->dropColumn([
                'order_type',
                'parent_order_id',
                'extend_reason',
                'attachment_path',
                'attachment_name',
                'estimated_wait_minutes',
                'in_delivery_at',
                'auto_completed',
            ]);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('is_hq');
        });
    }
};
