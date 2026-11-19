<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'branch_id',
        'room_id',
        'location_detail',
        'delivery_time',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecurringScheduleItem::class);
    }

    public function dates(): HasMany
    {
        return $this->hasMany(RecurringScheduleDate::class);
    }

    public function totalDailyCups(): int
    {
        return (int) $this->items()->sum('quantity');
    }
}
