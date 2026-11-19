<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringScheduleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'recurring_schedule_id',
        'drink_item_id',
        'quantity',
        'sugar_level',
        'ice_level',
        'custom_layer_selections',
        'instructions',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'custom_layer_selections' => 'array',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RecurringSchedule::class, 'recurring_schedule_id');
    }

    public function drinkItem(): BelongsTo
    {
        return $this->belongsTo(DrinkItem::class);
    }
}
