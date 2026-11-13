<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\Branch;
use App\Models\Room;
use App\Models\DrinkItem;
use App\Models\DrinkOrder;
use App\Models\DrinkOrderItem;
use App\Models\User;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

new class extends Component
{
    use WithFileUploads;

    public $activeTab = 'drinks'; // 'drinks', 'history'
    public $selectedBranchId;
    public $currentUserId;

    // --- Order Mode ---
    public $orderType = 'instant'; // 'instant', 'meeting'
    public $extendingOrderId = null;
    public $extendReason = 'spilled'; // 'spilled', 'extra_guests', 'additional'

    // --- Drink Selection & Cart ---
    public $selectedDrinkId = null;
    public $selectedSugar = '50%';
    public $selectedCoffee = 'Single Shot';
    public $selectedIce = 'Normal Ice';
    public $selectedMilk = 'Fresh Whole Milk';
    public $drinkQuantity = 1;
    public $drinkCart = [];

    // --- Delivery & Meeting Details ---
    public $selectedRoomId = null;
    public $quickTiming = 'asap'; // 'asap' | 'later_today'
    public $quickClock = '16:00';
    public $meetingDate;
    public $meetingClock = '09:00';
    public $peopleCount = 1;
    public $drinkNotes = '';
    public $attachment;
    public $historyLimit = 10;

    // Toast / Feedback message
    public $flashMessage = null;
    public $flashType = 'success';

    public function mount()
    {
        $user = Auth::user();
        if ($user) {
            $this->currentUserId = $user->id;
        }

        $firstBranch = Branch::where('is_hq', true)->first() ?? Branch::first();
        $this->selectedBranchId = $user?->branch_id ?? ($firstBranch ? $firstBranch->id : 1);

        $this->applyUserBranchConstraints();
        $this->meetingDate = $this->getMinMeetingDate();
        $this->meetingClock = '09:00';
        $this->quickClock = $this->getDefaultQuickClock();
    }

    public function getDefaultQuickClock(): string
    {
        $candidate = Carbon::now()->addHour()->startOfHour();
        if ($candidate->hour < 9) {
            return '09:00';
        }
        if ($candidate->hour > 17 || ($candidate->hour === 17 && $candidate->minute > 0)) {
            return '17:00';
        }
        return $candidate->format('H:i');
    }

    public function setQuickTiming(string $timing): void
    {
        if (!in_array($timing, ['asap', 'later_today'], true)) {
            return;
        }
        $this->quickTiming = $timing;
        if ($timing === 'later_today' && (!$this->quickClock || $this->quickClock <= Carbon::now()->format('H:i'))) {
            $this->quickClock = $this->getDefaultQuickClock();
        }
    }

    public function syncTelegramUser(?string $initData = null): void
    {
        if (empty($initData)) {
            return;
        }

        $user = app(TelegramService::class)->resolveUserFromInitData($initData);
        if ($user && $user->id !== $this->currentUserId) {
            Auth::login($user, remember: true);
            $this->currentUserId = $user->id;
            $this->applyUserBranchConstraints();
        } elseif (!$user && Auth::check()) {
            // If current session user was unlinked, bind this Telegram to current logged-in user
            app(TelegramService::class)->bindTelegramToUser(Auth::user(), $initData);
        }
    }

    public function logout(bool $unlinkTelegram = false): void
    {
        $user = Auth::user() ?? User::find($this->currentUserId);
        if ($unlinkTelegram && $user) {
            app(TelegramService::class)->unlinkUser($user);
        }

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirectRoute('login');
    }

    protected function isCurrentUserHq(): bool
    {
        $user = User::with('branch')->find($this->currentUserId);

        return !$user || !$user->branch || (bool) $user->branch->is_hq;
    }

    protected function applyUserBranchConstraints(): void
    {
        $user = User::with('branch')->find($this->currentUserId);
        if (!$user) {
            return;
        }

        $isHqUser = !$user->branch || $user->branch->is_hq;

        if (!$isHqUser && $user->branch_id) {
            $this->selectedBranchId = $user->branch_id;
            $this->orderType = 'meeting';
        } elseif ($user->branch_id && !$this->selectedBranchId) {
            $this->selectedBranchId = $user->branch_id;
        }

        $firstRoom = Room::where('branch_id', $this->selectedBranchId)->where('is_active', true)->first();
        $this->selectedRoomId = $firstRoom ? $firstRoom->id : null;
    }

    public function updatedSelectedBranchId(): void
    {
        $user = User::with('branch')->find($this->currentUserId);
        $isHqUser = !$user || !$user->branch || $user->branch->is_hq;

        if (!$isHqUser && $user->branch_id) {
            $this->selectedBranchId = $user->branch_id;
            $this->orderType = 'meeting';
        }

        $firstRoom = Room::where('branch_id', $this->selectedBranchId)->where('is_active', true)->first();
        $this->selectedRoomId = $firstRoom ? $firstRoom->id : null;
        $this->drinkCart = [];
    }

    public function setOrderType(string $type): void
    {
        if ($type === 'instant' && !$this->isCurrentUserHq()) {
            $this->flashMessage = __('cbvh.branch_meeting_only');
            $this->flashType = 'error';
            return;
        }

        $this->orderType = $type;
        if ($type === 'meeting' && (!$this->meetingDate || $this->meetingDate < $this->getMinMeetingDate())) {
            $this->meetingDate = $this->getMinMeetingDate();
        }
    }

    public function getMinMeetingDate(): string
    {
        return Carbon::today()->addDays(2)->format('Y-m-d');
    }

    public function isWithinWorkingHours(): bool
    {
        if (!(bool) Cache::get('enforce_working_hours', true)) {
            return true;
        }

        $hour = Carbon::now()->hour;
        $minute = Carbon::now()->minute;
        if ($hour < 8) return false;
        if ($hour > 17) return false;
        if ($hour === 17 && $minute > 0) return false;
        return true;
    }

    public function selectDrink($drinkId): void
    {
        $drink = DrinkItem::find($drinkId);
        if (!$drink) return;

        $this->selectedDrinkId = $drink->id;
        $config = $drink->options_config ?? [];

        $this->selectedSugar = $config['sugar_levels'][1] ?? '50%';
        $this->selectedCoffee = $config['coffee_levels'][0] ?? 'Single Shot';
        $this->selectedIce = $config['ice_levels'][1] ?? 'Normal Ice';
        $this->selectedMilk = $config['milk_types'][0] ?? 'Fresh Whole Milk';
        $this->drinkQuantity = 1;
    }

    public function addToCart(): void
    {
        $drink = DrinkItem::find($this->selectedDrinkId);
        if (!$drink) return;

        $qty = max(1, (int) $this->drinkQuantity);
        if ($this->orderType === 'instant' && !$this->extendingOrderId) {
            $usedToday = DrinkOrder::quickCupsUsedToday($this->currentUserId);
            $trayCups = (int) collect($this->drinkCart)->sum('quantity');
            $limit = DrinkOrder::quickDailyLimitForUser(Auth::user());

            if (($usedToday + $trayCups + $qty) > $limit) {
                $remaining = max(0, $limit - $usedToday - $trayCups);
                $this->selectedDrinkId = null;
                $this->flashMessage = __('cbvh.quick_daily_limit_exceeded', [
                    'limit' => $limit,
                    'remaining' => $remaining,
                ]);
                $this->flashType = 'error';
                return;
            }
        }

        $options = [];
        $config = $drink->options_config ?? [];
        if (!empty($config['sugar_levels'])) $options['sugar'] = $this->selectedSugar;
        if (!empty($config['coffee_levels'])) $options['coffee'] = $this->selectedCoffee;
        if (!empty($config['ice_levels'])) $options['ice'] = $this->selectedIce;
        if (!empty($config['milk_types'])) $options['milk'] = $this->selectedMilk;

        $this->drinkCart[] = [
            'drink_id' => $drink->id,
            'name' => $drink->name,
            'quantity' => $qty,
            'options' => $options,
        ];

        $this->selectedDrinkId = null;
        $this->flashMessage = "Added {$drink->name}!";
        $this->flashType = 'success';
    }

    public function removeFromCart($index): void
    {
        unset($this->drinkCart[$index]);
        $this->drinkCart = array_values($this->drinkCart);
    }

    public function startExtendOrder($orderId): void
    {
        $parent = DrinkOrder::where('id', $orderId)
            ->where('user_id', $this->currentUserId)
            ->where('status', 'in_delivery')
            ->first();
        if (!$parent) return;

        $this->extendingOrderId = $parent->id;
        $this->selectedBranchId = $parent->branch_id;
        $this->selectedRoomId = $parent->room_id;
        $this->extendReason = 'spilled';
        $this->peopleCount = 1;
        $this->drinkCart = [];
        $this->attachment = null;
        $this->activeTab = 'drinks';
        $this->flashMessage = __('cbvh.extending_order', ['id' => $parent->id]);
        $this->flashType = 'success';
    }

    public function cancelExtend(): void
    {
        $this->extendingOrderId = null;
        $this->drinkCart = [];
        $this->attachment = null;
        $this->applyUserBranchConstraints();
    }

    public function loadMoreHistory(): void
    {
        $this->historyLimit += 15;
    }

    public function confirmReceived($orderId): void
    {
        $order = DrinkOrder::where('id', $orderId)
            ->where('user_id', $this->currentUserId)
            ->first();

        if ($order && $order->status === 'in_delivery') {
            $order->update([
                'status' => 'completed',
                'completed_at' => now(),
                'auto_completed' => false,
            ]);

            $this->flashMessage = "#{$order->id} " . __('cbvh.status_completed');
            $this->flashType = 'success';
        }
    }

    public function submitDrinkOrder(): void
    {
        if (empty($this->drinkCart) || !$this->selectedRoomId) {
            $this->flashMessage = 'Please select at least one drink and location.';
            $this->flashType = 'error';
            return;
        }

        $totalCups = (int) collect($this->drinkCart)->sum('quantity');
        $branch = Branch::find($this->selectedBranchId);

        $isExtend = !empty($this->extendingOrderId);
        $finalOrderType = $isExtend ? 'extend' : $this->orderType;

        if ($finalOrderType === 'instant') {
            if (!$this->isCurrentUserHq()) {
                $this->flashMessage = __('cbvh.branch_meeting_only');
                $this->flashType = 'error';
                return;
            }

            $usedToday = DrinkOrder::quickCupsUsedToday($this->currentUserId);
            $limit = DrinkOrder::quickDailyLimitForUser(Auth::user());
            if (($usedToday + $totalCups) > $limit) {
                $this->flashMessage = __('cbvh.quick_daily_limit_exceeded', [
                    'limit' => $limit,
                    'remaining' => max(0, $limit - $usedToday),
                ]);
                $this->flashType = 'error';
                return;
            }

            if ($this->quickTiming === 'later_today') {
                if (!$this->quickClock || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $this->quickClock)) {
                    $this->flashMessage = __('cbvh.quick_later_rule');
                    $this->flashType = 'error';
                    return;
                }

                $quickHm = substr($this->quickClock, 0, 5);
                if ($quickHm < '08:00' || $quickHm > '17:00') {
                    $this->flashMessage = __('cbvh.quick_later_rule');
                    $this->flashType = 'error';
                    return;
                }

                $deliveryTime = Carbon::today()->setTimeFromTimeString($quickHm);
                if (Cache::get('enforce_working_hours', true) && $deliveryTime->lessThanOrEqualTo(Carbon::now())) {
                    $this->flashMessage = __('cbvh.quick_time_past');
                    $this->flashType = 'error';
                    return;
                }

                $waitMinutes = null;
            } else {
                if (!$this->isWithinWorkingHours()) {
                    $this->flashMessage = __('cbvh.closed_quick_hours');
                    $this->flashType = 'error';
                    return;
                }

                $waitMinutes = max(2, $totalCups * 2);
                $deliveryTime = Carbon::now()->addMinutes($waitMinutes);
            }
        } elseif ($finalOrderType === 'meeting') {
            $minDate = $this->getMinMeetingDate();
            if (!$this->meetingDate || $this->meetingDate < $minDate) {
                $formattedMinDate = \App\Support\CbvhLocale::formatDate(Carbon::parse($minDate));
                $this->flashMessage = __('cbvh.meeting_advance_rule') . " ({$formattedMinDate})";
                $this->flashType = 'error';
                return;
            }

            if (!$this->meetingClock || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $this->meetingClock)) {
                $this->flashMessage = __('cbvh.meeting_hours_rule');
                $this->flashType = 'error';
                return;
            }

            $clockHm = substr($this->meetingClock, 0, 5);
            if ($clockHm < '08:00' || $clockHm > '17:00') {
                $this->flashMessage = __('cbvh.meeting_hours_rule');
                $this->flashType = 'error';
                return;
            }

            $waitMinutes = null;
            $deliveryTime = Carbon::parse("{$this->meetingDate} {$clockHm}");
        } else {
            $waitMinutes = max(2, $totalCups * 2);
            $deliveryTime = Carbon::now()->addMinutes($waitMinutes);
        }

        $attachmentPath = null;
        $attachmentName = null;
        if ($this->attachment && in_array($finalOrderType, ['meeting', 'extend'])) {
            $this->validate([
                'attachment' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            ]);
            $attachmentName = $this->attachment->getClientOriginalName();
            $attachmentPath = $this->attachment->store('attachments', 'public');
        }

        $order = DrinkOrder::create([
            'user_id' => $this->currentUserId,
            'branch_id' => $this->selectedBranchId,
            'room_id' => $this->selectedRoomId,
            'order_type' => $finalOrderType,
            'parent_order_id' => $isExtend ? $this->extendingOrderId : null,
            'extend_reason' => $isExtend ? $this->extendReason : null,
            'delivery_time' => $deliveryTime,
            'estimated_wait_minutes' => $waitMinutes,
            'people_count' => in_array($finalOrderType, ['instant', 'extend'], true) ? $totalCups : max(1, (int) $this->peopleCount),
            'notes' => $this->drinkNotes,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        foreach ($this->drinkCart as $item) {
            DrinkOrderItem::create([
                'drink_order_id' => $order->id,
                'drink_item_id' => $item['drink_id'],
                'quantity' => $item['quantity'],
                'selected_options' => $item['options'],
            ]);
        }

        app(TelegramService::class)->notifyBaristaForDrinkOrder($order);

        $this->drinkCart = [];
        $this->drinkNotes = '';
        $this->attachment = null;
        $this->extendingOrderId = null;

        $this->flashMessage = "#{$order->id} — " . __('cbvh.status_approved');
        $this->flashType = 'success';
        $this->activeTab = 'history';
    }

    public function with()
    {
        DrinkOrder::autoCompleteExpiredDeliveries();

        $currentUser = User::with('branch')->find($this->currentUserId);
        $isHqUser = !$currentUser || !$currentUser->branch || $currentUser->branch->is_hq;

        $branches = $isHqUser
            ? Branch::where('is_active', true)->get()
            : Branch::where('id', $currentUser->branch_id)->get();

        $canUseQuick = $isHqUser;
        $quickUsedToday = DrinkOrder::quickCupsUsedToday($this->currentUserId);
        $quickDailyLimit = DrinkOrder::quickDailyLimitForUser($currentUser);

        $activeOrders = DrinkOrder::where('user_id', $this->currentUserId)
            ->whereIn('status', ['approved', 'preparing', 'in_delivery'])
            ->with('items.drinkItem', 'room', 'branch', 'parentOrder')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $pastBaseQuery = DrinkOrder::where('user_id', $this->currentUserId)
            ->whereNotIn('status', ['approved', 'preparing', 'in_delivery']);

        $pastTotalCount = (clone $pastBaseQuery)->count();
        $pastOrders = $pastBaseQuery
            ->with('items.drinkItem', 'room', 'branch', 'parentOrder')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->take($this->historyLimit)
            ->get();

        return [
            'currentUser' => $currentUser,
            'isHqUser' => $isHqUser,
            'canUseQuick' => $canUseQuick,
            'quickDailyLimit' => $quickDailyLimit,
            'quickCupsUsedToday' => $quickUsedToday,
            'quickCupsRemaining' => max(0, $quickDailyLimit - $quickUsedToday),
            'minMeetingDate' => $this->getMinMeetingDate(),
            'withinWorkingHours' => $this->isWithinWorkingHours(),
            'branches' => $branches,
            'rooms' => Room::where('branch_id', $this->selectedBranchId)->where('is_active', true)->get(),
            'drinks' => DrinkItem::where('branch_id', $this->selectedBranchId)->where('is_available', true)->get(),
            'activeOrderCount' => $activeOrders->count(),
            'pastOrderCount' => $pastOrders->count(),
            'hasMoreHistory' => $pastTotalCount > $pastOrders->count(),
            'myDrinkOrders' => $activeOrders->concat($pastOrders),
            'totalTrayCups' => (int) collect($this->drinkCart)->sum('quantity'),
        ];
    }
};
?>

<div x-data="{ confirmLogout: false }" x-init="if (window.Telegram?.WebApp?.initData) { $wire.syncTelegramUser(window.Telegram.WebApp.initData); }"
     class="flex-1 flex flex-col max-w-lg mx-auto w-full pb-28 min-h-screen overflow-x-hidden bg-[#FAF6F3]/60 dark:bg-[#1A1412]/90">
    <!-- Top Luxury Header -->
    <header class="cbvh-safe-header bg-white/95 dark:bg-[#241C19]/95 backdrop-blur-md border-b border-[#E8DDD6] dark:border-[#3D2F29] px-4 pb-3.5 sticky top-0 z-20 shadow-[0_2px_16px_rgba(158,107,85,0.06)]">
        <div class="flex items-center justify-between gap-2 mb-3">
            <div class="flex items-center space-x-2.5 min-w-0">
                <img src="{{ asset('images/cbvh-mark.png') }}" alt="CBVH" class="w-10 h-10 rounded-xl object-cover shrink-0 shadow-xs border border-[#E8DDD6]" />
                <div class="min-w-0">
                    <h1 class="text-sm font-bold tracking-tight text-[#2C211C] dark:text-[#F5EDE7] truncate">{{ __('cbvh.app_name') }}</h1>
                    <p class="text-[11px] text-[#7C6A60] dark:text-[#BBA79D] tracking-wide truncate">
                        {{ $currentUser?->name }} • {{ $isHqUser ? __('cbvh.hq_access') : ($currentUser?->branch?->code ?? __('cbvh.branch_access')) }}
                    </p>
                </div>
            </div>

            <!-- Role Links + Language Switch + Theme + Logout -->
            <div class="flex items-center space-x-1 shrink-0">
                @if($currentUser?->isAdmin())
                    <a href="/admin" target="_blank"
                       class="px-2 py-1 rounded-full text-[10px] font-semibold bg-[#F5EDE7] dark:bg-[#3A2C26] text-[#835440] dark:text-[#E8CFC1]">
                        {{ __('cbvh.admin') }}
                    </a>
                @endif
                @if($currentUser?->isBarista())
                    <a href="/barista" target="_blank"
                       class="px-2 py-1 rounded-full text-[10px] font-semibold bg-[#F5EDE7] dark:bg-[#3A2C26] text-[#835440] dark:text-[#E8CFC1]">
                        {{ __('cbvh.barista') }}
                    </a>
                @endif

                <a href="{{ route('lang.switch', app()->getLocale() === 'km' ? 'en' : 'km') }}"
                   class="px-2 py-1 rounded-full text-[10px] font-bold border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#2F2420] text-[#835440] dark:text-[#E8CFC1]">
                    {{ app()->getLocale() === 'km' ? 'EN' : 'ខ្មែរ' }}
                </a>

                <button type="button" onclick="window.toggleCbvhTheme()" title="{{ __('cbvh.switch_theme') }}"
                        class="w-7 h-7 rounded-full border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#2F2420] flex items-center justify-center text-[#835440] dark:text-[#E8CFC1] cursor-pointer">
                    <svg class="w-3.5 h-3.5 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41m11.32-11.32 1.41-1.41"/>
                    </svg>
                    <svg class="w-3.5 h-3.5 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>

                <button type="button" @click="confirmLogout = true" title="{{ __('cbvh.unlink_logout') }}"
                        class="w-7 h-7 rounded-full border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#2F2420] flex items-center justify-center text-rose-600 cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Branch Selector (Locked for non-HQ branch users) -->
        <div class="relative flex items-center space-x-2 bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] px-3 py-2 rounded-xl">
            <svg class="w-3.5 h-3.5 text-[#9E6B55] shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
            </svg>
            <span class="text-[11px] font-semibold uppercase tracking-wider text-[#7C6A60] dark:text-[#BBA79D] shrink-0">{{ __('cbvh.branch') }}</span>
            <select wire:model.live="selectedBranchId" @disabled(!$isHqUser || $extendingOrderId)
                    class="bg-transparent text-xs font-bold text-[#2C211C] dark:text-[#F5EDE7] focus:outline-hidden w-full min-w-0 pr-6 truncate cursor-pointer disabled:opacity-75">
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" class="bg-white text-[#2C211C]">{{ \App\Support\CbvhLocale::branch($b->name) }}</option>
                @endforeach
            </select>
            @if($isHqUser && !$extendingOrderId)
                <svg class="w-3.5 h-3.5 text-[#7C6A60] pointer-events-none shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                </svg>
            @endif
        </div>
    </header>

    <!-- Floating Toast Notification (iOS / Telegram Dynamic Capsule Style) -->
    @if($flashMessage)
        <div wire:key="tma-toast-{{ md5($flashMessage . ($flashType ?? 'success') . microtime()) }}"
             x-data="{ show: true }"
             x-init="setTimeout(() => { show = false; setTimeout(() => $wire.set('flashMessage', null), 300); }, 2800)"
             x-show="show"
             x-transition:enter="transition ease-out duration-300 transform"
             x-transition:enter-start="opacity-0 -translate-y-4 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-200 transform"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 -translate-y-3 scale-95"
             class="fixed top-16 left-4 right-4 z-50 max-w-sm mx-auto pointer-events-auto">
            <div class="flex items-center space-x-2.5 px-4 py-3 rounded-2xl shadow-2xl backdrop-blur-md border {{ ($flashType ?? 'success') === 'success' ? 'bg-white/95 dark:bg-[#241C19]/95 border-[#9E6B55]/40 text-[#2C211C] dark:text-[#F5EDE7]' : 'bg-rose-50/95 dark:bg-rose-950/90 border-rose-300 dark:border-rose-800 text-rose-900 dark:text-rose-100' }}">
                <span class="w-6 h-6 rounded-full flex items-center justify-center shrink-0 text-xs font-black {{ ($flashType ?? 'success') === 'success' ? 'bg-[#9E6B55] text-white shadow-xs' : 'bg-rose-500 text-white shadow-xs' }}">
                    {{ ($flashType ?? 'success') === 'success' ? '✓' : '!' }}
                </span>
                <span class="text-xs font-bold leading-tight flex-1">{{ $flashMessage }}</span>
                <button type="button" @click="show = false; setTimeout(() => $wire.set('flashMessage', null), 300)"
                        class="p-1 text-[#7C6A60] hover:text-[#2C211C] dark:hover:text-white rounded-lg transition cursor-pointer text-base leading-none">
                    &times;
                </button>
            </div>
        </div>
    @endif

    <main class="flex-1 p-4 space-y-5">

        @if($activeTab === 'drinks')
            <div wire:key="tma-tab-drinks" class="cbvh-fade-slide space-y-4">

                @if($extendingOrderId)
                    <div wire:key="tma-extend-box" class="cbvh-fade-slide bg-[#F5EDE7] dark:bg-[#3A2C26] p-4 rounded-2xl border border-[#9E6B55] space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-widest text-[#9E6B55] dark:text-[#E8CFC1]">{{ __('cbvh.extend_badge') }}</span>
                                <h2 class="text-sm font-bold text-[#2C211C] dark:text-[#F5EDE7]">🔄 {{ __('cbvh.extending_order', ['id' => $extendingOrderId]) }}</h2>
                            </div>
                            <button wire:click="cancelExtend" class="text-xs font-bold text-rose-600 underline cursor-pointer">{{ __('cbvh.cancel') }}</button>
                        </div>

                        <div>
                            <label class="block text-[11px] font-medium text-[#7C6A60] dark:text-[#BBA79D] mb-1.5">{{ __('cbvh.reason') }}</label>
                            <div class="grid grid-cols-3 gap-1.5">
                                @foreach([
                                    'spilled' => '💧 ' . __('cbvh.reason_spilled'),
                                    'extra_guests' => '👥 ' . __('cbvh.reason_extra_guests'),
                                    'additional' => '➕ ' . __('cbvh.reason_additional'),
                                ] as $rKey => $rLabel)
                                    <button type="button" wire:click="$set('extendReason', '{{ $rKey }}')"
                                            class="py-2 px-2 text-[11px] font-bold rounded-xl border transition {{ $extendReason === $rKey ? 'bg-[#9E6B55] text-white border-[#9E6B55]' : 'bg-white dark:bg-[#241C19] border-[#E8DDD6] text-[#2C211C] dark:text-[#F5EDE7]' }}">
                                        {{ $rLabel }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @else
                    <div class="bg-white dark:bg-[#241C19] p-3 rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-[0_2px_12px_rgba(158,107,85,0.05)] space-y-2.5 overflow-hidden">
                        @if($canUseQuick)
                            <div class="relative grid grid-cols-2 bg-[#FAF6F3] dark:bg-[#1A1412] p-1 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29]">
                                <!-- Gliding active pill -->
                                <div aria-hidden="true"
                                     class="pointer-events-none absolute top-1 bottom-1 left-1 w-[calc(50%-0.25rem)] rounded-lg bg-[#9E6B55] shadow-xs transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] {{ $orderType === 'meeting' ? 'translate-x-full' : 'translate-x-0' }}"></div>
                                <button type="button" wire:click="setOrderType('instant')"
                                        class="relative z-10 py-2.5 text-xs font-bold rounded-lg transition-colors duration-200 cursor-pointer {{ $orderType === 'instant' ? 'text-white' : 'text-[#7C6A60] dark:text-[#BBA79D]' }}">
                                    ⚡ {{ __('cbvh.quick_drink') }}
                                </button>
                                <button type="button" wire:click="setOrderType('meeting')"
                                        class="relative z-10 py-2.5 text-xs font-bold rounded-lg transition-colors duration-200 cursor-pointer {{ $orderType === 'meeting' ? 'text-white' : 'text-[#7C6A60] dark:text-[#BBA79D]' }}">
                                    📅 {{ __('cbvh.meeting_drink') }}
                                </button>
                            </div>
                        @else
                            <div class="flex items-center justify-between px-1">
                                <span class="text-xs font-bold text-[#9E6B55]">📅 {{ __('cbvh.meeting_drink') }}</span>
                                <span class="text-[11px] text-[#7C6A60]">{{ __('cbvh.branch_meeting_only') }}</span>
                            </div>
                        @endif

                        @if($orderType === 'instant')
                            <div wire:key="tma-rule-instant" class="cbvh-slide-from-left space-y-1.5">
                                @if($withinWorkingHours)
                                    <div class="text-[11px] text-[#7C6A60] dark:text-[#BBA79D] px-1 flex items-center justify-between">
                                        <span>⏱️ {{ __('cbvh.quick_wait_rule') }}</span>
                                        <span class="text-emerald-700 font-semibold">● {{ __('cbvh.open_hours') }}</span>
                                    </div>
                                @else
                                    <div class="p-2.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-[11px] font-semibold">
                                        ⏰ {{ __('cbvh.closed_quick_hours') }}
                                    </div>
                                @endif
                                <div class="text-[11px] px-2.5 py-1.5 rounded-xl font-semibold flex items-center justify-between {{ $quickCupsRemaining > 0 ? 'bg-[#FAF6F3] dark:bg-[#1A1412] text-[#835440] dark:text-[#E8CFC1] border border-[#E8DDD6] dark:border-[#3D2F29]' : 'bg-amber-50 border border-amber-200 text-amber-800' }}">
                                    <span>☕ {{ __('cbvh.quick_daily_remaining', ['remaining' => $quickCupsRemaining, 'limit' => $quickDailyLimit]) }}</span>
                                </div>
                            </div>
                        @else
                            <div wire:key="tma-rule-meeting" class="cbvh-slide-from-right text-[11px] text-[#7C6A60] dark:text-[#BBA79D] px-1">
                                📌 {{ __('cbvh.meeting_advance_rule') }}
                            </div>
                        @endif
                    </div>
                @endif

                <!-- Delivery Details Card -->
                <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-[0_2px_12px_rgba(158,107,85,0.05)] space-y-3 overflow-hidden">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-[#9E6B55] dark:text-[#E8CFC1] uppercase tracking-widest">{{ __('cbvh.delivery_details') }}</span>
                        <span class="text-[11px] text-[#7C6A60] dark:text-[#BBA79D]">
                            {{ $extendingOrderId ? __('cbvh.urgent') : ($orderType === 'instant' ? ($quickTiming === 'later_today' ? __('cbvh.quick_later_today') : __('cbvh.immediate')) : __('cbvh.scheduled')) }}
                        </span>
                    </div>

                    <div class="space-y-2.5">
                        <div>
                            <label class="block text-[11px] font-medium text-[#7C6A60] dark:text-[#BBA79D] mb-1">{{ __('cbvh.room_lounge') }}</label>
                            <div class="relative">
                                <select wire:model="selectedRoomId" @disabled($extendingOrderId)
                                        class="w-full min-w-0 text-xs bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7] rounded-xl pl-3.5 pr-9 py-2.5 font-semibold focus:border-[#9E6B55] focus:outline-hidden truncate disabled:opacity-75">
                                    @foreach($rooms as $r)
                                        <option value="{{ $r->id }}">{{ \App\Support\CbvhLocale::room($r->name) }} ({{ \App\Support\CbvhLocale::floor($r->floor) }})</option>
                                    @endforeach
                                </select>
                                @if(!$extendingOrderId)
                                    <svg class="w-3.5 h-3.5 text-[#7C6A60] pointer-events-none absolute right-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                                    </svg>
                                @endif
                            </div>
                        </div>

                        @if($orderType === 'instant' && !$extendingOrderId)
                            <div wire:key="tma-fields-instant" class="cbvh-slide-from-left">
                                <label class="block text-[11px] font-medium text-[#7C6A60] dark:text-[#BBA79D] mb-1">{{ __('cbvh.quick_timing_label') }}</label>
                                <div class="relative grid grid-cols-2 bg-[#FAF6F3] dark:bg-[#1A1412] p-1 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29]">
                                    <div aria-hidden="true"
                                         class="pointer-events-none absolute top-1 bottom-1 left-1 w-[calc(50%-0.25rem)] rounded-lg bg-[#9E6B55] shadow-xs transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] {{ $quickTiming === 'later_today' ? 'translate-x-full' : 'translate-x-0' }}"></div>
                                    <button type="button" wire:click="setQuickTiming('asap')"
                                            class="relative z-10 py-2 px-2 text-xs font-bold rounded-lg transition-colors duration-200 cursor-pointer {{ $quickTiming === 'asap' ? 'text-white' : 'text-[#7C6A60] dark:text-[#BBA79D]' }}">
                                        ⚡ {{ __('cbvh.quick_asap') }}
                                    </button>
                                    <button type="button" wire:click="setQuickTiming('later_today')"
                                            class="relative z-10 py-2 px-2 text-xs font-bold rounded-lg transition-colors duration-200 cursor-pointer {{ $quickTiming === 'later_today' ? 'text-white' : 'text-[#7C6A60] dark:text-[#BBA79D]' }}">
                                        🕒 {{ __('cbvh.quick_later_today') }}
                                    </button>
                                </div>

                                @if($quickTiming === 'later_today')
                                    <div wire:key="tma-quick-later" class="cbvh-fade-slide grid grid-cols-12 gap-2 mt-2">
                                        <div class="col-span-7 min-w-0">
                                            <div class="w-full text-xs bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#7C6A60] rounded-xl px-3 py-2.5 font-semibold truncate">
                                                📅 {{ __('cbvh.today_only') }} ({{ \App\Support\CbvhLocale::formatDate(\Carbon\Carbon::today()) }})
                                            </div>
                                        </div>
                                        <div class="col-span-5 min-w-0">
                                            <input type="time" wire:model="quickClock" min="08:00" max="17:00"
                                                   class="w-full min-w-0 block text-xs bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7] rounded-xl px-3 py-2.5 font-semibold focus:border-[#9E6B55] focus:outline-hidden" />
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @elseif($orderType === 'meeting' && !$extendingOrderId)
                            <div wire:key="tma-fields-meeting" class="cbvh-slide-from-right">
                                <label class="block text-[11px] font-medium text-[#7C6A60] dark:text-[#BBA79D] mb-1">
                                    {{ __('cbvh.meeting_datetime') }} <span class="text-[#9E6B55]">({{ __('cbvh.earliest') }}: {{ \App\Support\CbvhLocale::formatDate(\Carbon\Carbon::parse($minMeetingDate)) }})</span>
                                </label>
                                <div class="grid grid-cols-12 gap-2">
                                    <div class="col-span-7 min-w-0">
                                        @php
                                            $selectedDateDisplay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $meetingDate)
                                                ? \App\Support\CbvhLocale::formatDate(\Carbon\Carbon::parse($meetingDate))
                                                : '—';
                                        @endphp
                                        <div class="relative w-full text-xs bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7] rounded-xl px-3 py-2.5 font-semibold flex items-center justify-between focus-within:border-[#9E6B55]">
                                            <span class="truncate">{{ $selectedDateDisplay }}</span>
                                            <svg class="w-4 h-4 text-[#7C6A60] shrink-0 ml-1" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z"/>
                                            </svg>
                                            <input type="date" wire:model.live="meetingDate" min="{{ $minMeetingDate }}"
                                                   onclick="try { this.showPicker && this.showPicker(); } catch (e) {}"
                                                   class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10" />
                                        </div>
                                    </div>
                                    <div class="col-span-5 min-w-0">
                                        <input type="time" wire:model="meetingClock" min="08:00" max="17:00"
                                               class="w-full min-w-0 block text-xs bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7] rounded-xl px-3 py-2.5 font-semibold focus:border-[#9E6B55] focus:outline-hidden" />
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if($orderType === 'meeting' && !$extendingOrderId)
                            <div>
                                <label class="block text-[11px] font-medium text-[#7C6A60] dark:text-[#BBA79D] mb-1">{{ __('cbvh.people_count') }}</label>
                                <input type="number" min="1" max="100" wire:model="peopleCount"
                                       class="w-full text-xs bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7] rounded-xl px-3.5 py-2.5 font-semibold focus:border-[#9E6B55] focus:outline-hidden" />
                            </div>
                        @elseif($orderType === 'instant' && !$extendingOrderId)
                            <div class="text-[11px] text-[#7C6A60] dark:text-[#BBA79D] px-0.5">
                                👥 {{ __('cbvh.quick_auto_guests') }}
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Beverage Menu Grid -->
                <div>
                    <div class="flex items-baseline justify-between mb-3 px-0.5">
                        <h2 class="text-base font-bold text-[#2C211C] dark:text-[#F5EDE7] tracking-tight">{{ __('cbvh.beverage_menu') }}</h2>
                        <span class="text-[11px] font-medium text-[#9E6B55] dark:text-[#E8CFC1]">{{ __('cbvh.tap_to_customize') }}</span>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        @foreach($drinks as $drink)
                            <div wire:click="selectDrink({{ $drink->id }})"
                                 class="cursor-pointer bg-white dark:bg-[#241C19] p-4 rounded-2xl border {{ $selectedDrinkId === $drink->id ? 'border-[#9E6B55] ring-2 ring-[#9E6B55]/20' : 'border-[#E8DDD6] dark:border-[#3D2F29]' }} hover:border-[#9E6B55] active:scale-[0.97] transition-all duration-150 shadow-[0_2px_12px_rgba(158,107,85,0.05)] flex flex-col justify-between group">
                                <div>
                                    <div class="w-9 h-9 rounded-xl bg-[#FAF6F3] dark:bg-[#3A2C26] border border-[#E8DDD6] dark:border-[#4A3830] flex items-center justify-center text-lg mb-3">
                                        @if($drink->category === 'coffee') ☕
                                        @elseif($drink->category === 'tea') 🍵
                                        @elseif($drink->category === 'juice') 🍊
                                        @else 🥤
                                        @endif
                                    </div>
                                    <div class="font-bold text-sm text-[#2C211C] dark:text-[#F5EDE7] leading-snug">{{ \App\Support\CbvhLocale::drink($drink->name) }}</div>
                                    <p class="text-[11px] text-[#7C6A60] dark:text-[#BBA79D] mt-1 line-clamp-2 leading-relaxed">{{ \App\Support\CbvhLocale::drinkDescription($drink->description) }}</p>
                                </div>
                                <div class="mt-4">
                                    <span class="inline-flex items-center justify-center w-full py-1.5 rounded-xl text-[11px] font-bold bg-[#F5EDE7] dark:bg-[#3A2C26] text-[#835440] dark:text-[#E8CFC1] group-hover:bg-[#9E6B55] group-hover:text-white transition">
                                        {{ __('cbvh.customize') }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Tray / Cart -->
                @if(count($drinkCart) > 0)
                    <div wire:key="tma-tray-box" class="cbvh-fade-slide bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#9E6B55]/50 shadow-[0_4px_18px_rgba(158,107,85,0.12)] space-y-3">
                        <div class="flex items-center justify-between border-b border-[#E8DDD6] dark:border-[#3D2F29] pb-2.5">
                            <span class="font-bold text-sm text-[#2C211C] dark:text-[#F5EDE7]">{{ __('cbvh.your_tray', ['count' => $totalTrayCups]) }}</span>
                            @if($extendingOrderId || ($orderType === 'instant' && $quickTiming === 'asap'))
                                <span class="text-[11px] font-bold text-[#9E6B55]">⏱️ {{ __('cbvh.est_wait', ['mins' => $totalTrayCups * 2]) }}</span>
                            @elseif($orderType === 'instant' && $quickTiming === 'later_today')
                                <span class="text-[11px] font-bold text-[#9E6B55]">🕒 {{ __('cbvh.quick_later_today') }}</span>
                            @else
                                <span class="text-[11px] font-bold text-[#9E6B55]">📅 {{ __('cbvh.scheduled_meeting') }}</span>
                            @endif
                        </div>

                        <div class="space-y-2">
                            @foreach($drinkCart as $idx => $item)
                                <div class="flex items-center justify-between text-xs py-2 border-b border-[#FAF6F3] dark:border-[#3D2F29]/50">
                                    <div>
                                        <div class="font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ $item['quantity'] }}x {{ \App\Support\CbvhLocale::drink($item['name']) }}</div>
                                        <div class="text-[11px] text-[#7C6A60] dark:text-[#BBA79D]">
                                            @foreach($item['options'] as $k => $v)
                                                <span>{{ \App\Support\CbvhLocale::optionKey($k) }}: {{ \App\Support\CbvhLocale::option($v) }}</span>@if(!$loop->last) • @endif
                                            @endforeach
                                        </div>
                                    </div>
                                    <button wire:click="removeFromCart({{ $idx }})" class="text-rose-600 hover:text-rose-700 font-semibold text-[11px] px-2 py-1">{{ __('cbvh.remove') }}</button>
                                </div>
                            @endforeach
                        </div>

                        <div>
                            <input type="text" wire:model="drinkNotes" placeholder="{{ __('cbvh.notes_placeholder') }}"
                                   class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] focus:border-[#9E6B55] focus:outline-hidden" />
                        </div>

                        @if($orderType === 'meeting' || $extendingOrderId)
                            <div class="p-3 rounded-xl bg-[#FAF6F3] dark:bg-[#1A1412] border border-dashed border-[#E8DDD6] dark:border-[#3D2F29] space-y-1.5">
                                <label class="block text-[11px] font-bold text-[#7C6A60] dark:text-[#BBA79D]">
                                    📎 {{ __('cbvh.attachment_label') }}
                                </label>
                                <input type="file" wire:model="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf"
                                       class="w-full text-[11px] text-[#7C6A60] file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[11px] file:font-bold file:bg-[#F5EDE7] file:text-[#835440]" />
                                <div wire:loading wire:target="attachment" class="text-[11px] text-[#9E6B55] font-semibold">{{ __('cbvh.uploading') }}</div>
                                @error('attachment') <span class="text-[11px] text-rose-500 block">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        <button wire:click="submitDrinkOrder" wire:loading.attr="disabled"
                                class="w-full py-3.5 bg-[#9E6B55] hover:bg-[#835440] text-white font-bold text-sm rounded-2xl shadow-md transition cursor-pointer">
                            {{ $extendingOrderId ? __('cbvh.btn_send_extend') : ($orderType === 'instant' ? __('cbvh.btn_send_quick') : __('cbvh.btn_send_meeting')) }}
                        </button>
                    </div>
                @endif
            </div>
        @endif

        @if($activeTab === 'history')
            <div wire:key="tma-tab-history" wire:poll.8s class="cbvh-fade-slide space-y-4">
                <h2 class="text-base font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ __('cbvh.your_drink_orders') }}</h2>

                <div class="space-y-3">
                    @php $shownPastHeader = false; @endphp
                    @forelse($myDrinkOrders as $idx => $order)
                        @php $isActiveStatus = in_array($order->status, ['approved', 'preparing', 'in_delivery'], true); @endphp
                        @if($idx === 0 && $isActiveStatus)
                            <div class="text-[11px] font-bold uppercase tracking-wider text-[#9E6B55] px-1">
                                ● {{ __('cbvh.active_orders_section') }} ({{ $activeOrderCount }})
                            </div>
                        @elseif(!$isActiveStatus && !$shownPastHeader && $activeOrderCount > 0)
                            @php $shownPastHeader = true; @endphp
                            <div class="text-[11px] font-bold uppercase tracking-wider text-[#7C6A60] px-1 pt-2">
                                {{ __('cbvh.past_orders_section') }}
                            </div>
                        @endif
                        <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border {{ $isActiveStatus ? 'border-[#9E6B55]/45' : 'border-[#E8DDD6] dark:border-[#3D2F29]' }} shadow-2xs space-y-2.5">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center space-x-1.5 min-w-0">
                                    <span class="font-bold text-xs text-[#2C211C] dark:text-[#F5EDE7] truncate">
                                        #{{ $order->id }} • {{ \App\Support\CbvhLocale::room($order->room?->name) }}
                                    </span>
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase shrink-0
                                        {{ $order->order_type === 'instant' ? 'bg-amber-100 text-amber-800' : ($order->order_type === 'extend' ? 'bg-purple-100 text-purple-800' : 'bg-[#FAF6F3] text-[#7C6A60] border border-[#E8DDD6]') }}">
                                        {{ $order->order_type === 'extend' ? 'EXTEND #' . $order->parent_order_id : $order->order_type }}
                                    </span>
                                </div>

                                @php
                                    $statusText = match($order->status) {
                                        'approved' => __('cbvh.status_approved'),
                                        'preparing' => __('cbvh.status_preparing'),
                                        'in_delivery' => __('cbvh.status_in_delivery'),
                                        'completed' => $order->auto_completed ? __('cbvh.status_auto_completed') : __('cbvh.status_completed'),
                                        'rejected_by_barista' => __('cbvh.status_rejected'),
                                        default => $order->status,
                                    };
                                @endphp
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold shrink-0
                                    {{ $order->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : ($order->status === 'in_delivery' ? 'bg-blue-100 text-blue-800 animate-pulse' : (str_contains($order->status, 'rejected') ? 'bg-rose-100 text-rose-800' : 'bg-[#F5EDE7] text-[#835440] dark:bg-[#3A2C26] dark:text-[#E8CFC1]')) }}">
                                    {{ $statusText }}
                                </span>
                            </div>

                            <div class="text-xs text-[#7C6A60] dark:text-[#BBA79D] space-y-0.5">
                                @foreach($order->items as $it)
                                    <div>• {{ $it->quantity }}x <strong class="text-[#2C211C] dark:text-[#F5EDE7]">{{ \App\Support\CbvhLocale::drink($it->drinkItem?->name) }}</strong></div>
                                @endforeach
                            </div>

                            <div class="text-[11px] text-[#7C6A60] flex items-center justify-between">
                                <span>
                                    {{ $order->delivery_time ? \App\Support\CbvhLocale::formatDateTime($order->delivery_time) : $order->created_at->diffForHumans() }}
                                    @if($order->estimated_wait_minutes)
                                        • ~{{ $order->estimated_wait_minutes }}m
                                    @endif
                                </span>
                                @if($order->attachment_name)
                                    <span>📎 {{ $order->attachment_name }}</span>
                                @endif
                            </div>

                            @if($order->status === 'in_delivery')
                                <div class="pt-2 border-t border-[#FAF6F3] dark:border-[#3D2F29] space-y-2">
                                    <div class="text-[11px] text-blue-700 dark:text-blue-300 font-medium">
                                        🚚 {{ __('cbvh.in_delivery_notice') }}
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <button wire:click="confirmReceived({{ $order->id }})"
                                                class="py-2.5 px-3 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer">
                                            ✅ {{ __('cbvh.btn_received_done') }}
                                        </button>
                                        <button wire:click="startExtendOrder({{ $order->id }})"
                                                class="py-2.5 px-3 bg-[#F5EDE7] hover:bg-[#9E6B55] text-[#835440] hover:text-white font-bold text-xs rounded-xl border border-[#E8DDD6] transition cursor-pointer">
                                            ➕ {{ __('cbvh.btn_order_more_spilled') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-xs text-[#7C6A60] p-5 bg-white dark:bg-[#241C19] border border-[#E8DDD6] dark:border-[#3D2F29] rounded-2xl text-center">{{ __('cbvh.no_orders_yet') }}</div>
                    @endforelse

                    @if($hasMoreHistory)
                        <button type="button" wire:click="loadMoreHistory"
                                class="w-full py-2.5 bg-white dark:bg-[#241C19] hover:bg-[#F5EDE7] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#835440] dark:text-[#E8CFC1] font-bold text-xs rounded-xl transition cursor-pointer">
                            {{ __('cbvh.show_more_orders') }}
                        </button>
                    @endif
                </div>
            </div>
        @endif
    </main>

    <!-- Bottom Navigation Bar -->
    <nav class="cbvh-safe-bottom-nav fixed bottom-0 left-0 right-0 max-w-lg mx-auto bg-white/95 dark:bg-[#241C19]/95 backdrop-blur-md border-t border-[#E8DDD6] dark:border-[#3D2F29] px-10 pt-2.5 flex items-center justify-around z-20 shadow-[0_-4px_20px_rgba(158,107,85,0.06)]">
        <button wire:click="$set('activeTab', 'drinks')"
                class="flex flex-col items-center py-1 px-6 rounded-xl transition cursor-pointer {{ $activeTab === 'drinks' ? 'text-[#9E6B55] dark:text-[#E8CFC1] font-bold' : 'text-[#7C6A60]/70 hover:text-[#2C211C] dark:text-[#BBA79D]/60' }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18 8h1a4 4 0 0 1 0 8h-1M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8Zm4-4v2m4-2v2m4-2v2"/>
            </svg>
            <span class="text-[11px] mt-1">{{ __('cbvh.tab_order_drinks') }}</span>
        </button>

        <button wire:click="$set('activeTab', 'history')"
                class="relative flex flex-col items-center py-1 px-6 rounded-xl transition cursor-pointer {{ $activeTab === 'history' ? 'text-[#9E6B55] dark:text-[#E8CFC1] font-bold' : 'text-[#7C6A60]/70 hover:text-[#2C211C] dark:text-[#BBA79D]/60' }}">
            @if($activeOrderCount > 0)
                <span class="absolute -top-0.5 right-4 min-w-[18px] h-[18px] px-1 rounded-full bg-[#9E6B55] text-white text-[10px] font-bold flex items-center justify-center">
                    {{ $activeOrderCount }}
                </span>
            @endif
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4"/>
            </svg>
            <span class="text-[11px] mt-1">{{ __('cbvh.tab_my_orders') }}</span>
        </button>
    </nav>

    <!-- Fullscreen Customization Bottom Sheet (Outside animated tab wrappers so fixed positioning covers header & bottom nav) -->
    @if($selectedDrinkId)
        @php $currentDrink = $drinks->firstWhere('id', $selectedDrinkId); @endphp
        @if($currentDrink)
            <div wire:key="tma-drink-modal-{{ $currentDrink->id }}"
                 wire:click.self="$set('selectedDrinkId', null)"
                 class="cbvh-backdrop-fade fixed inset-0 bg-[#2C211C]/50 backdrop-blur-xs z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
                <div class="cbvh-sheet-up cbvh-safe-bottom-nav bg-white dark:bg-[#241C19] w-full max-w-md rounded-t-3xl sm:rounded-3xl px-5 pt-4 sm:pb-5 border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xl space-y-3 max-h-[88dvh] overflow-y-auto">
                    <div class="flex items-center justify-between border-b border-[#E8DDD6] dark:border-[#3D2F29] pb-2.5">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-widest text-[#9E6B55]">{{ __('cbvh.customize_beverage') }}</span>
                            <h3 class="font-bold text-base text-[#2C211C] dark:text-[#F5EDE7]">{{ \App\Support\CbvhLocale::drink($currentDrink->name) }}</h3>
                        </div>
                        <button wire:click="$set('selectedDrinkId', null)" class="w-8 h-8 rounded-full bg-[#FAF6F3] dark:bg-[#3A2C26] text-[#7C6A60] hover:text-[#2C211C] flex items-center justify-center text-lg font-bold cursor-pointer">&times;</button>
                    </div>

                    @php $opts = $currentDrink->options_config ?? []; @endphp

                    @if(!empty($opts['sugar_levels']))
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wider text-[#7C6A60] dark:text-[#BBA79D] mb-1.5">{{ __('cbvh.sugar_level') }}</label>
                            <div class="grid grid-cols-5 gap-1.5">
                                @foreach($opts['sugar_levels'] as $sugar)
                                    <button type="button" wire:click="$set('selectedSugar', '{{ $sugar }}')"
                                        class="py-1.5 text-[11px] font-semibold rounded-xl border text-center transition cursor-pointer {{ $selectedSugar === $sugar ? 'bg-[#9E6B55] text-white border-[#9E6B55] shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#1A1412] border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7]' }}">
                                        {{ \App\Support\CbvhLocale::option($sugar) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(!empty($opts['coffee_levels']))
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wider text-[#7C6A60] dark:text-[#BBA79D] mb-1.5">{{ __('cbvh.coffee_strength') }}</label>
                            <div class="grid grid-cols-3 gap-1.5">
                                @foreach($opts['coffee_levels'] as $coffee)
                                    <button type="button" wire:click="$set('selectedCoffee', '{{ $coffee }}')"
                                        class="py-1.5 text-[11px] font-semibold rounded-xl border text-center transition cursor-pointer {{ $selectedCoffee === $coffee ? 'bg-[#9E6B55] text-white border-[#9E6B55] shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#1A1412] border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7]' }}">
                                        {{ \App\Support\CbvhLocale::option($coffee) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(!empty($opts['ice_levels']))
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wider text-[#7C6A60] dark:text-[#BBA79D] mb-1.5">{{ __('cbvh.ice_level') }}</label>
                            <div class="grid grid-cols-3 gap-1.5">
                                @foreach($opts['ice_levels'] as $ice)
                                    <button type="button" wire:click="$set('selectedIce', '{{ $ice }}')"
                                        class="py-1.5 text-[11px] font-semibold rounded-xl border text-center transition cursor-pointer {{ $selectedIce === $ice ? 'bg-[#9E6B55] text-white border-[#9E6B55] shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#1A1412] border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7]' }}">
                                        {{ \App\Support\CbvhLocale::option($ice) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(!empty($opts['milk_types']))
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wider text-[#7C6A60] dark:text-[#BBA79D] mb-1.5">{{ __('cbvh.milk_option') }}</label>
                            <div class="grid grid-cols-2 gap-1.5">
                                @foreach($opts['milk_types'] as $milk)
                                    <button type="button" wire:click="$set('selectedMilk', '{{ $milk }}')"
                                        class="py-1.5 text-[11px] font-semibold rounded-xl border text-center transition cursor-pointer {{ $selectedMilk === $milk ? 'bg-[#9E6B55] text-white border-[#9E6B55] shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#1A1412] border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7]' }}">
                                        {{ \App\Support\CbvhLocale::option($milk) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex items-center justify-between pt-2 border-t border-[#E8DDD6] dark:border-[#3D2F29]">
                        <span class="text-xs font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ __('cbvh.quantity') }}</span>
                        <div class="flex items-center space-x-3">
                            <button type="button" wire:click="$set('drinkQuantity', {{ max(1, $drinkQuantity - 1) }})" class="w-8 h-8 rounded-full border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] flex items-center justify-center font-bold text-base cursor-pointer">-</button>
                            <span class="font-bold text-sm w-4 text-center">{{ $drinkQuantity }}</span>
                            <button type="button" wire:click="$set('drinkQuantity', {{ $drinkQuantity + 1 }})" class="w-8 h-8 rounded-full border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] flex items-center justify-center font-bold text-base cursor-pointer">+</button>
                        </div>
                    </div>

                    <button wire:click="addToCart" class="w-full py-3 bg-[#9E6B55] hover:bg-[#835440] active:scale-[0.98] text-white font-bold text-sm rounded-2xl shadow-md transition cursor-pointer">
                        {{ __('cbvh.add_to_tray') }}
                    </button>
                </div>
            </div>
        @endif
    @endif

    <x-logout-confirm-modal mode="livewire" />
</div>
