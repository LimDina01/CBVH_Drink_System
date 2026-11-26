<?php

namespace App\Services;

use App\Models\DrinkOrder;
use App\Models\DrinkOrderItem;
use App\Models\RecurringSchedule;
use App\Models\RecurringScheduleDate;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecurringOrderService
{
    /**
     * Dispatch due recurring orders for a given date (defaults to today).
     * Automatically creates pre-approved DrinkOrders and sends Telegram notifications.
     */
    public function dispatchDueOrders(?Carbon $targetDate = null): array
    {
        $targetDate = $targetDate ? $targetDate->copy() : today();
        $dateStr = $targetDate->toDateString();

        return Cache::lock("recurring_dispatch_lock_{$dateStr}", 15)->get(function () use ($dateStr) {
            $dueDates = RecurringScheduleDate::whereDate('delivery_date', $dateStr)
                ->where('status', 'scheduled')
                ->whereNull('generated_order_id')
                ->with(['schedule.items.drinkItem', 'schedule.branch', 'schedule.room'])
                ->get();

            $generatedOrders = [];

            foreach ($dueDates as $scheduleDate) {
                $schedule = $scheduleDate->schedule;
                if (! $schedule || ! $schedule->is_active || $schedule->items->isEmpty()) {
                    continue;
                }

                try {
                    $order = DB::transaction(function () use ($schedule, $scheduleDate, $dateStr) {
                        $lockedDate = RecurringScheduleDate::whereKey($scheduleDate->id)
                            ->where('status', 'scheduled')
                            ->whereNull('generated_order_id')
                            ->lockForUpdate()
                            ->first();

                        if (! $lockedDate) {
                            return null;
                        }

                        $admin = $schedule->creator ?? User::where('role', 'admin')->first() ?? User::first();
                        $deliveryTime = Carbon::parse($dateStr.' '.($schedule->delivery_time ?: '08:30'));
                        $totalCups = (int) $schedule->items->sum('quantity');

                        // Fallback room if room_id is empty: ensure it belongs to this branch
                        $roomId = $schedule->room_id
                            ?: Room::where('branch_id', $schedule->branch_id)->value('id');

                        if (! $roomId) {
                            $defaultRoom = Room::firstOrCreate(
                                ['branch_id' => $schedule->branch_id, 'name' => 'Main Office Area'],
                                ['floor' => 'Ground Floor', 'capacity' => 20, 'is_active' => true]
                            );
                            $roomId = $defaultRoom->id;
                        }

                        $locNote = $schedule->location_detail ? "📍 {$schedule->location_detail}" : '';
                        $notes = trim($locNote.($schedule->notes ? ($locNote ? ' — ' : '').$schedule->notes : ''));
                        if (empty($notes)) {
                            $notes = '🗓️ '.($schedule->title ?: 'Schedule Order');
                        }

                        $order = DrinkOrder::create([
                            'user_id' => $admin ? $admin->id : 1,
                            'branch_id' => $schedule->branch_id,
                            'room_id' => $roomId,
                            'order_type' => 'recurring',
                            'delivery_time' => $deliveryTime,
                            'people_count' => max(1, $totalCups),
                            'notes' => $notes,
                            'status' => 'approved', // Pre-approved by standing schedule
                            'approved_by' => $admin ? $admin->id : null,
                            'approved_at' => now(),
                        ]);

                        foreach ($schedule->items as $item) {
                            $options = [];
                            if ($item->sugar_level) {
                                $options['sugar'] = $item->sugar_level;
                            }
                            if ($item->ice_level) {
                                $options['ice'] = $item->ice_level;
                            }
                            if (! empty($item->custom_layer_selections)) {
                                $options['custom_layers'] = $item->custom_layer_selections;
                            }

                            DrinkOrderItem::create([
                                'drink_order_id' => $order->id,
                                'drink_item_id' => $item->drink_item_id,
                                'drink_name_snapshot' => $item->drinkItem?->name,
                                'drink_category_snapshot' => $item->drinkItem?->category,
                                'quantity' => $item->quantity,
                                'selected_options' => ! empty($options) ? $options : null,
                                'instructions' => $item->instructions,
                            ]);
                        }

                        $lockedDate->update([
                            'status' => 'generated',
                            'generated_order_id' => $order->id,
                        ]);

                        return $order;
                    });

                    if ($order) {
                        try {
                            app(TelegramService::class)->notifyBaristaForDrinkOrder($order);
                        } catch (\Throwable $e) {
                            Log::error("Failed to notify barista on Telegram for recurring order #{$order->id}: ".$e->getMessage());
                        }

                        $generatedOrders[] = $order;
                    }
                } catch (\Throwable $e) {
                    Log::error("Error generating recurring order for schedule #{$schedule->id} on {$dateStr}: ".$e->getMessage());
                }
            }

            return $generatedOrders;
        }) ?? [];
    }

    /**
     * Prefill working days for a month (excludes Sundays).
     */
    public function prefillMonth(RecurringSchedule $schedule, int $year, int $month, bool $includeSaturday = false): void
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $daysInMonth = $start->daysInMonth;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $current = Carbon::createFromDate($year, $month, $day);
            $dayOfWeek = $current->dayOfWeek; // 0 = Sunday, 6 = Saturday

            $isWorkingDay = $includeSaturday
                ? ($dayOfWeek !== 0) // Mon - Sat
                : ($dayOfWeek >= 1 && $dayOfWeek <= 5); // Mon - Fri

            if ($isWorkingDay) {
                $dateStr = $current->toDateString();
                $exists = RecurringScheduleDate::where('recurring_schedule_id', $schedule->id)
                    ->whereDate('delivery_date', $dateStr)
                    ->exists();

                if (! $exists) {
                    RecurringScheduleDate::create([
                        'recurring_schedule_id' => $schedule->id,
                        'delivery_date' => $dateStr,
                        'status' => 'scheduled',
                    ]);
                }
            }
        }
    }

    /**
     * Copy schedule pattern from previous month.
     * Takes each active day-of-month or day-of-week that was scheduled last month.
     */
    public function copyPreviousMonth(RecurringSchedule $schedule, int $targetYear, int $targetMonth): int
    {
        $targetStart = Carbon::createFromDate($targetYear, $targetMonth, 1);
        $prevMonth = $targetStart->copy()->subMonth();

        $prevDates = RecurringScheduleDate::where('recurring_schedule_id', $schedule->id)
            ->whereYear('delivery_date', $prevMonth->year)
            ->whereMonth('delivery_date', $prevMonth->month)
            ->whereIn('status', ['scheduled', 'generated'])
            ->pluck('delivery_date')
            ->map(fn ($d) => Carbon::parse($d)->day)
            ->toArray();

        if (empty($prevDates)) {
            // Default to Mon-Fri if previous month had no schedule
            $this->prefillMonth($schedule, $targetYear, $targetMonth, false);

            return 0;
        }

        $targetDaysInMonth = $targetStart->daysInMonth;
        $added = 0;

        foreach ($prevDates as $dayNum) {
            if ($dayNum <= $targetDaysInMonth) {
                $targetDate = Carbon::createFromDate($targetYear, $targetMonth, $dayNum);
                // Never schedule on Sunday
                if ($targetDate->dayOfWeek !== 0) {
                    $tDateStr = $targetDate->toDateString();
                    $exists = RecurringScheduleDate::where('recurring_schedule_id', $schedule->id)
                        ->whereDate('delivery_date', $tDateStr)
                        ->exists();

                    if (! $exists) {
                        RecurringScheduleDate::create([
                            'recurring_schedule_id' => $schedule->id,
                            'delivery_date' => $tDateStr,
                            'status' => 'scheduled',
                        ]);
                        $added++;
                    }
                }
            }
        }

        return $added;
    }

    /**
     * Toggle a specific date in the schedule (add if missing, delete if scheduled).
     */
    public function toggleDate(RecurringSchedule $schedule, string $dateStr): string
    {
        $existing = RecurringScheduleDate::where('recurring_schedule_id', $schedule->id)
            ->whereDate('delivery_date', $dateStr)
            ->first();

        if ($existing) {
            if ($existing->status === 'generated') {
                return 'generated'; // Cannot toggle off already generated order
            }
            $existing->delete();

            return 'removed';
        }

        RecurringScheduleDate::create([
            'recurring_schedule_id' => $schedule->id,
            'delivery_date' => $dateStr,
            'status' => 'scheduled',
        ]);

        return 'added';
    }

    /**
     * Clear all non-generated scheduled dates for a month.
     */
    public function clearMonth(RecurringSchedule $schedule, int $year, int $month): void
    {
        RecurringScheduleDate::where('recurring_schedule_id', $schedule->id)
            ->whereYear('delivery_date', $year)
            ->whereMonth('delivery_date', $month)
            ->where('status', 'scheduled')
            ->delete();
    }
}
