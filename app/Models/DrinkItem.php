<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrinkItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'name_km',
        'category',
        'description',
        'description_km',
        'options_config',
        'is_available',
    ];

    protected $casts = [
        'options_config' => 'array',
        'is_available' => 'boolean',
    ];

    public function getDisplayNameAttribute(): string
    {
        if (app()->getLocale() === 'km' && !empty($this->name_km)) {
            return $this->name_km;
        }

        return \App\Support\CbvhLocale::drink($this->name);
    }

    public function getDisplayDescriptionAttribute(): ?string
    {
        if (app()->getLocale() === 'km' && !empty($this->description_km)) {
            return $this->description_km;
        }

        return \App\Support\CbvhLocale::drinkDescription($this->description) ?: $this->description;
    }

    /**
     * Normalized dynamic layers for customization options.
     * Supports both new dynamic format and legacy preset format.
     */
    public function getNormalizedLayersAttribute(): array
    {
        $config = $this->options_config ?? [];
        if (!is_array($config)) {
            return [];
        }

        if (!empty($config['layers']) && is_array($config['layers'])) {
            return $config['layers'];
        }

        // Convert legacy key-based options into uniform dynamic layers
        $layers = [];
        if (!empty($config['sugar_levels'])) {
            $layers[] = [
                'id' => 'sugar_level',
                'name_en' => 'Sugar Level',
                'name_km' => 'កម្រិតជាតិផ្អែម',
                'options' => array_map(fn($o) => [
                    'en' => (string) $o,
                    'km' => \App\Support\CbvhLocale::option((string) $o),
                ], $config['sugar_levels']),
            ];
        }

        if (!empty($config['coffee_levels'])) {
            $layers[] = [
                'id' => 'coffee_strength',
                'name_en' => 'Coffee Strength',
                'name_km' => 'កម្រិតកាហ្វេ',
                'options' => array_map(fn($o) => [
                    'en' => (string) $o,
                    'km' => \App\Support\CbvhLocale::option((string) $o),
                ], $config['coffee_levels']),
            ];
        }

        if (!empty($config['ice_levels'])) {
            $layers[] = [
                'id' => 'ice_level',
                'name_en' => 'Ice Level',
                'name_km' => 'កម្រិតទឹកកក',
                'options' => array_map(fn($o) => [
                    'en' => (string) $o,
                    'km' => \App\Support\CbvhLocale::option((string) $o),
                ], $config['ice_levels']),
            ];
        }

        if (!empty($config['milk_types'])) {
            $layers[] = [
                'id' => 'milk_option',
                'name_en' => 'Milk Option',
                'name_km' => 'ជម្រើសទឹកដោះគោ',
                'options' => array_map(fn($o) => [
                    'en' => (string) $o,
                    'km' => \App\Support\CbvhLocale::option((string) $o),
                ], $config['milk_types']),
            ];
        }

        return $layers;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(DrinkOrderItem::class);
    }
}
