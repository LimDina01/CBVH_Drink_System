<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrinkOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'drink_order_id',
        'drink_item_id',
        'quantity',
        'selected_options',
        'instructions',
    ];

    protected $casts = [
        'selected_options' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(DrinkOrder::class, 'drink_order_id');
    }

    public function drinkItem(): BelongsTo
    {
        return $this->belongsTo(DrinkItem::class, 'drink_item_id');
    }
}
