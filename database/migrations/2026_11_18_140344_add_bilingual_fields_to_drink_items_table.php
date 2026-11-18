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
        Schema::table('drink_items', function (Blueprint $table) {
            $table->string('name_km')->nullable()->after('name');
            $table->text('description_km')->nullable()->after('description');
        });

        // Seed Khmer names for existing default items
        \Illuminate\Support\Facades\DB::table('drink_items')
            ->where('name', 'Ice Milk Coffee')
            ->update([
                'name_km' => 'កាហ្វេទឹកដោះគោទឹកកក',
                'description_km' => 'កាហ្វេទឹកដោះគោទឹកកកបែបបុរាណ ឆុងស្រស់ៗជាមួយទឹកដោះគោខាប់ដ៏ឈ្ងុយឆ្ងាញ់។',
            ]);

        \Illuminate\Support\Facades\DB::table('drink_items')
            ->where('name', 'Red Lemon Tea')
            ->update([
                'name_km' => 'តែក្រូចឆ្មារក្រហម',
                'description_km' => 'តែក្រហមឆុងស្រស់ លាយជាមួយទឹកក្រូចឆ្មារធម្មជាតិស្រស់ស្រាយ។',
            ]);

        \Illuminate\Support\Facades\DB::table('drink_items')
            ->where('name', 'Samrong Basil Seeds')
            ->update([
                'name_km' => 'សំរងគ្រាប់ជី',
                'description_km' => 'ភេសជ្ជៈត្រជាក់បែបបុរាណ ផ្សំពីផ្លែសំរង និងគ្រាប់ជីជួយសម្រាលកម្តៅក្នុងខ្លួន។',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drink_items', function (Blueprint $table) {
            $table->dropColumn(['name_km', 'description_km']);
        });
    }
};
