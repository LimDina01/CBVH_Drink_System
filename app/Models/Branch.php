<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'is_hq',
        'address',
        'is_active',
    ];

    protected $casts = [
        'is_hq' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function drinkItems(): HasMany
    {
        return $this->hasMany(DrinkItem::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
