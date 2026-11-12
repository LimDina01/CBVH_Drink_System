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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. "Toyota Camry", "Ford Transit 12-Seat"
            $table->string('plate_number')->unique();
            $table->string('category')->default('sedan'); // sedan, van, suv, pickup
            $table->integer('capacity')->default(4); // number of passenger seats
            $table->string('status')->default('available'); // available, maintenance, out_of_service
            $table->string('color')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('license_number')->nullable();
            $table->string('phone');
            $table->string('status')->default('available'); // available, on_trip, on_leave, off_duty
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicles');
    }
};
