<?php

namespace App\Models;

use App\Services\TelegramService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrinkOrder extends Model
{
    use HasFactory;

    public const QUICK_DAILY_CUP_LIMIT = 10;
    public const ADMIN_QUICK_DAILY_CUP_LIMIT = 50;

    /**
     * Determine daily quick cup limit based on user role (Admin = 50, Staff = 10).
     */
    public static function quickDailyLimitForUser(?User $user): int
    {
        if ($user && $user->isAdmin()) {
            return self::ADMIN_QUICK_DAILY_CUP_LIMIT;
        }

        return self::QUICK_DAILY_CUP_LIMIT;
    }

    protected $fillable = [
        'user_id',
        'branch_id',
        'room_id',
        'order_type', // 'instant', 'meeting', 'extend'
        'parent_order_id',
        'extend_reason', // 'spilled', 'extra_guests', 'additional'
        'delivery_time',
        'estimated_wait_minutes',
        'people_count',
        'notes',
        'attachment_path',
        'attachment_name',
        'status', // 'approved', 'preparing', 'in_delivery', 'completed', 'rejected_by_barista'
        'approved_by',
        'approved_at',
        'approver_rejection_reason',
        'barista_id',
        'barista_accepted_at',
        'barista_rejection_reason',
        'ready_at',
        'in_delivery_at',
        'completed_at',
        'auto_completed',
    ];

    protected $casts = [
        'delivery_time' => 'datetime',
        'approved_at' => 'datetime',
        'barista_accepted_at' => 'datetime',
        'ready_at' => 'datetime',
        'in_delivery_at' => 'datetime',
        'completed_at' => 'datetime',
        'auto_completed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function barista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barista_id');
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(DrinkOrder::class, 'parent_order_id');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(DrinkOrder::class, 'parent_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DrinkOrderItem::class);
    }

    /**
     * Auto-complete orders that have been in 'in_delivery' for 10+ minutes.
     */
    public static function autoCompleteExpiredDeliveries(bool $notifyTelegram = true): int
    {
        $expired = static::with('user')
            ->where('status', 'in_delivery')
            ->whereNotNull('in_delivery_at')
            ->where('in_delivery_at', '<=', now()->subMinutes(10))
            ->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        $telegram = $notifyTelegram ? app(TelegramService::class) : null;

        foreach ($expired as $order) {
            $order->update([
                'status' => 'completed',
                'completed_at' => now(),
                'auto_completed' => true,
            ]);

            if ($telegram && $order->user && $order->user->telegram_chat_id) {
                try {
                    $telegram->sendMessage(
                        $order->user->telegram_chat_id,
                        "✅ <b>Order #{$order->id} Auto-Completed:</b> Marked as delivered after 10 minutes. Need extra drinks or replacement? Open <b>My Orders</b> and tap <b>Order More / Spilled</b>."
                    );
                } catch (\Throwable $e) {
                    // Never block polling if Telegram fails
                }
            }
        }

        return $expired->count();
    }

    public function totalCups(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /**
     * Total Quick Drink ('instant') cups a user has ordered today.
     */
    public static function quickCupsUsedToday(?int $userId): int
    {
        if (!$userId) {
            return 0;
        }

        return (int) DrinkOrderItem::whereHas('order', function ($query) use ($userId) {
            $query->where('user_id', $userId)
                ->where('order_type', 'instant')
                ->where('status', '!=', 'rejected_by_barista')
                ->whereDate('created_at', today());
        })->sum('quantity');
    }
}
