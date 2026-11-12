<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('car_bookings');
        Schema::dropIfExists('vehicle_maintenances');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicles');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Car booking tables were intentionally split into a separate standalone project.
    }
};
