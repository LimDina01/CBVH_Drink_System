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
        // Drink Menu Items
        Schema::create('drink_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. "Iced Americano", "Hot Cappuccino", "Green Tea Latte"
            $table->string('category')->default('coffee'); // coffee, tea, juice, other
            $table->text('description')->nullable();
            // JSON options for customizable levels (sugar, coffee shot, ice, milk)
            $table->json('options_config')->nullable(); 
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });

        // Drink Orders Header
        Schema::create('drink_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Requester
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete(); // Delivery location
            
            $table->dateTime('delivery_time')->nullable(); // When is it needed
            $table->integer('people_count')->default(1);
            $table->text('notes')->nullable();

            // Workflow status:
            // pending_approval -> approved -> barista_accepted -> preparing -> ready -> completed (or rejected_by_approver, rejected_by_barista)
            $table->string('status')->default('pending_approval');

            // Approver info (HOD or Admin)
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('approver_rejection_reason')->nullable();

            // Barista info
            $table->foreignId('barista_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('barista_accepted_at')->nullable();
            $table->text('barista_rejection_reason')->nullable();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();
        });

        // Drink Order Specific Items
        Schema::create('drink_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drink_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drink_item_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            // Selected options: {"sugar": "50%", "coffee": "double", "ice": "less_ice"}
            $table->json('selected_options')->nullable(); 
            $table->string('instructions')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drink_order_items');
        Schema::dropIfExists('drink_orders');
        Schema::dropIfExists('drink_items');
    }
};
