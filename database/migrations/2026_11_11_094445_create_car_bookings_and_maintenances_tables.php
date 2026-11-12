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
        // Maintenance / Checkup slots blocked by Monitor
        Schema::create('vehicle_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('reason'); // e.g. "Periodic oil change", "AC inspection", "Routine checkup"
            $table->string('status')->default('scheduled'); // scheduled, in_progress, completed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Car Bookings
        Schema::create('car_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Requester
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            
            // Trip details
            $table->string('category_requested')->default('sedan'); // sedan, van, suv, pickup
            $table->integer('passenger_count')->default(1);
            $table->string('purpose'); // client_meeting, executive, staff_travel, delivery
            $table->string('trip_type')->default('one_way'); // one_way, round_trip
            $table->dateTime('departure_time');
            $table->dateTime('return_time')->nullable(); // Required for round_trip
            $table->string('origin_address');
            $table->string('destination_address');
            $table->text('remarks')->nullable();

            // Assignment by Fleet Monitor
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            
            // Workflow Status
            // pending_approval -> approved_assigned -> driver_acknowledged -> in_transit -> arrived -> completed (or rejected, cancelled)
            $table->string('status')->default('pending_approval');
            
            // Approver info (Fleet Monitor or HOD)
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Driver Execution timestamps
            $table->dateTime('driver_acknowledged_at')->nullable();
            $table->dateTime('trip_started_at')->nullable();
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_bookings');
        Schema::dropIfExists('vehicle_maintenances');
    }
};
