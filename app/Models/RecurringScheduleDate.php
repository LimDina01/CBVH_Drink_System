<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringScheduleDate extends Model
{
    use HasFactory;

    protected $fillable = [
        'recurring_schedule_id',
        'delivery_date',
        'status',
        'generated_order_id',
    ];

    protected $casts = [
        'delivery_date' => 'date:Y-m-d',
    ];

    public function setDeliveryDateAttribute($value): void
    {
        $this->attributes['delivery_date'] = \Carbon\Carbon::parse($value)->toDateString();
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RecurringSchedule::class, 'recurring_schedule_id');
    }

    public function generatedOrder(): BelongsTo
    {
        return $this->belongsTo(DrinkOrder::class, 'generated_order_id');
    }
}
