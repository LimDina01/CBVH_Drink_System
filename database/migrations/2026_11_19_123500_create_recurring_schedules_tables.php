<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('Daily Standing Order');
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_detail')->nullable(); // Optional room, floor, or desk note
            $table->string('delivery_time', 10)->default('08:30'); // HH:MM
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recurring_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drink_item_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->string('sugar_level')->nullable();
            $table->string('ice_level')->nullable();
            $table->json('custom_layer_selections')->nullable();
            $table->string('instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('recurring_schedule_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('delivery_date');
            $table->string('status')->default('scheduled'); // 'scheduled', 'generated', 'skipped'
            $table->foreignId('generated_order_id')->nullable()->constrained('drink_orders')->nullOnDelete();
            $table->timestamps();

            $table->unique(['recurring_schedule_id', 'delivery_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_schedule_dates');
        Schema::dropIfExists('recurring_schedule_items');
        Schema::dropIfExists('recurring_schedules');
    }
};
