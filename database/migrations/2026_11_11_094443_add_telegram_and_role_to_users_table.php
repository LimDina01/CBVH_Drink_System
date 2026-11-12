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
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id')->nullable()->index();
            $table->string('telegram_username')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('role')->default('employee'); // employee, hod, barista, monitor, driver, admin
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['telegram_chat_id', 'telegram_username', 'phone', 'role', 'branch_id']);
        });
    }
};
