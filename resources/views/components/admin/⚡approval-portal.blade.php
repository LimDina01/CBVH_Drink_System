<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Branch;
use App\Models\DrinkOrder;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

new class extends Component
{
    use WithPagination;

    public $activeSection = 'reports'; // 'reports', 'team'

    // --- Report Filters ---
    public $filterBranchId = 'all';
    public $filterUserId = 'all';
    public $filterType = 'all'; // 'all', 'instant', 'meeting', 'extend'
    public $filterStatus = 'all';
    public $filterPeriod = 'this_month'; // 'today', 'this_month', 'last_month', 'all', 'custom'
    public $searchQuery = '';
    public int $perPage = 15;
    public $dateFrom;
    public $dateTo;

    // --- Create Staff Form ---
    public string $newName = '';
    public string $newEmail = '';
    public string $newPassword = '';
    public string $newRole = 'employee';
    public $newBranchId = null;

    // --- Settings ---
    public $enforceWorkingHours = true;
    public bool $showOptionsInTable = false;
    public $flashMessage = null;
    public $flashType = 'success';

    public function mount()
    {
        $this->setPeriod('this_month');
        $this->enforceWorkingHours = (bool) Cache::get('enforce_working_hours', true);
        $this->newBranchId = Branch::first()?->id;
    }

    public function setPeriod(string $period): void
    {
        $this->filterPeriod = $period;
        if ($period === 'today') {
            $this->dateFrom = today()->toDateString();
            $this->dateTo = today()->toDateString();
        } elseif ($period === 'this_month') {
            $this->dateFrom = now()->startOfMonth()->toDateString();
            $this->dateTo = now()->endOfMonth()->toDateString();
        } elseif ($period === 'last_month') {
            $this->dateFrom = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
            $this->dateTo = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        } elseif ($period === 'all') {
            $this->dateFrom = null;
            $this->dateTo = null;
        }
        $this->resetPage();
    }

    public function updated($propertyName): void
    {
        if (in_array($propertyName, ['dateFrom', 'dateTo'], true)) {
            $this->filterPeriod = 'custom';
        }
        if (in_array($propertyName, ['filterBranchId', 'filterUserId', 'filterType', 'filterStatus', 'dateFrom', 'dateTo', 'searchQuery', 'filterPeriod', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function createStaffAccount(): void
    {
        $this->validate([
            'newName' => 'required|min:2',
            'newEmail' => 'required|email|unique:users,email',
            'newPassword' => 'required|min:4',
            'newRole' => 'required|in:employee,barista,admin',
            'newBranchId' => 'required|exists:branches,id',
        ]);

        User::create([
            'name' => trim($this->newName),
            'email' => trim($this->newEmail),
            'password' => Hash::make($this->newPassword),
            'role' => $this->newRole,
            'branch_id' => $this->newBranchId,
        ]);

        $this->newName = '';
        $this->newEmail = '';
        $this->newPassword = '';
        $this->flashMessage = 'Staff account created! They can now sign in once to bind Telegram.';
        $this->flashType = 'success';
    }

    public function unlinkTelegram($userId): void
    {
        $user = User::find($userId);
        if ($user) {
            app(TelegramService::class)->unlinkUser($user);
            $this->flashMessage = "Unlinked Telegram from {$user->name}.";
            $this->flashType = 'success';
        }
    }

    public function toggleWorkingHoursRule()
    {
        $this->enforceWorkingHours = !$this->enforceWorkingHours;
        Cache::forever('enforce_working_hours', $this->enforceWorkingHours);

        $this->flashMessage = $this->enforceWorkingHours
            ? __('cbvh.enforced_hours')
            : __('cbvh.testing_24_7');
        $this->flashType = 'success';
    }

    public function markOrderCompleted($orderId)
    {
        $order = DrinkOrder::find($orderId);
        if ($order) {
            $order->update([
                'status' => 'completed',
                'completed_at' => now(),
                'auto_completed' => false,
            ]);
            $this->flashMessage = "#{$orderId} — " . __('cbvh.status_completed');
            $this->flashType = 'success';
        }
    }

    public function sendLegacyToBarista($orderId)
    {
        $order = DrinkOrder::find($orderId);
        if ($order && $order->status === 'pending_approval') {
            $order->update([
                'status' => 'approved',
                'approved_at' => now(),
            ]);
            app(TelegramService::class)->notifyBaristaForDrinkOrder($order);
            $this->flashMessage = "#{$orderId} forwarded to Barista.";
            $this->flashType = 'success';
        }
    }

    public function updateUserRole($userId, $role)
    {
        $user = User::find($userId);
        if ($user && in_array($role, ['employee', 'barista', 'admin'])) {
            $user->update(['role' => $role]);
            $this->flashMessage = "Updated {$user->name} (" . strtoupper($role) . ").";
            $this->flashType = 'success';
        }
    }

    public function updateUserBranch($userId, $branchId)
    {
        $user = User::find($userId);
        if ($user && Branch::where('id', $branchId)->exists()) {
            $user->update(['branch_id' => $branchId]);
            $this->flashMessage = "Updated {$user->name}'s branch.";
            $this->flashType = 'success';
        }
    }

    public function sendTestTelegram($userId)
    {
        $user = User::find($userId);
        if (!$user || !$user->telegram_chat_id) {
            $this->flashMessage = 'User has not linked Telegram yet.';
            $this->flashType = 'error';
            return;
        }

        $res = app(TelegramService::class)->sendMessage(
            $user->telegram_chat_id,
            "✅ <b>CBVH Drink System Test Alert:</b> Telegram notifications are working! (Role: " . strtoupper($user->role) . ")"
        );

        if ($res && ($res['ok'] ?? false)) {
            $this->flashMessage = "Test message sent to {$user->name} on Telegram!";
            $this->flashType = 'success';
        } else {
            $this->flashMessage = "Failed to send Telegram message.";
            $this->flashType = 'error';
        }
    }

    public function registerWebhook()
    {
        $webhookUrl = rtrim(request()->getSchemeAndHttpHost(), '/') . '/telegram/webhook';
        $res = app(TelegramService::class)->setWebhook($webhookUrl);

        if ($res && ($res['ok'] ?? false)) {
            $this->flashMessage = "Telegram Bot Webhook connected to: {$webhookUrl}";
            $this->flashType = 'success';
        } else {
            $desc = $res['description'] ?? 'Open this page via your HTTPS Cloudflare URL first.';
            $this->flashMessage = "Webhook failed: {$desc}";
            $this->flashType = 'error';
        }
    }

    protected function buildFilteredOrdersQuery()
    {
        $query = DrinkOrder::with(['user', 'branch', 'room', 'items.drinkItem'])->latest();

        if ($this->filterBranchId !== 'all') {
            $query->where('branch_id', $this->filterBranchId);
        }

        if ($this->filterUserId !== 'all') {
            $query->where('user_id', $this->filterUserId);
        }

        if ($this->filterType !== 'all') {
            $query->where('order_type', $this->filterType);
        }

        if ($this->filterStatus !== 'all') {
            $query->where('status', $this->filterStatus);
        }

        if ($this->dateFrom) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        if (!empty(trim($this->searchQuery))) {
            $s = trim($this->searchQuery);
            $query->where(function ($q) use ($s) {
                $q->where('id', 'like', "%{$s}%")
                    ->orWhere('notes', 'like', "%{$s}%")
                    ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('room', fn($rq) => $rq->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('items.drinkItem', fn($dq) => $dq->where('name', 'like', "%{$s}%"));
            });
        }

        return $query;
    }

    public function exportCsv()
    {
        $orders = $this->buildFilteredOrdersQuery()->get();
        $filename = 'cbvh-drinks-report-' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($orders) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Order ID',
                'Order Type',
                'Parent Order ID',
                'Extend Reason',
                'Status',
                'Branch',
                'Room / Location',
                'Requester',
                'People Count',
                'Total Cups',
                'Drinks Summary',
                'Notes',
                'Reference Attachment',
                'Target / Meeting Time',
                'Ordered At',
                'In Delivery At',
                'Completed At',
                'Auto Completed (10m)',
            ]);

            foreach ($orders as $order) {
                // Group drinks by clean base name (e.g. Hot Latte + Iced Latte => Latte) without sugar/ice clutter
                $drinksList = $order->items
                    ->groupBy(fn($it) => \App\Support\CbvhLocale::toBaseDrinkName($it->drinkItem?->name))
                    ->map(function ($group, $baseName) {
                        $qty = $group->sum('quantity');
                        return "{$qty}x {$baseName}";
                    })
                    ->implode(' | ');

                fputcsv($handle, [
                    $order->id,
                    strtoupper($order->order_type),
                    $order->parent_order_id ?? '',
                    $order->extend_reason ?? '',
                    strtoupper($order->status),
                    $order->branch?->name ?? '',
                    $order->room?->name ?? '',
                    $order->user?->name ?? '',
                    $order->people_count,
                    $order->items->sum('quantity'),
                    $drinksList,
                    $order->notes ?? '',
                    $order->attachment_name ?? '',
                    $order->delivery_time?->format('Y-m-d H:i') ?? '',
                    $order->created_at?->format('Y-m-d H:i') ?? '',
                    $order->in_delivery_at?->format('Y-m-d H:i') ?? '',
                    $order->completed_at?->format('Y-m-d H:i') ?? '',
                    $order->auto_completed ? 'YES' : 'NO',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportDrinkTotalsCsv()
    {
        $orders = $this->buildFilteredOrdersQuery()->with('items.drinkItem')->get();
        $filename = 'cbvh-drink-summary-' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($orders) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Drink Item',
                'Category',
                'Total Cups Ordered',
            ]);

            $summary = [];
            foreach ($orders as $order) {
                foreach ($order->items as $it) {
                    $base = \App\Support\CbvhLocale::toBaseDrinkName($it->drinkItem?->name ?? 'Other');
                    $category = $it->drinkItem?->category ?? 'drink';
                    if (!isset($summary[$base])) {
                        $summary[$base] = [
                            'name' => $base,
                            'category' => $category,
                            'cups' => 0,
                        ];
                    }
                    $summary[$base]['cups'] += (int) $it->quantity;
                }
            }

            uasort($summary, fn($a, $b) => $b['cups'] <=> $a['cups']);

            foreach ($summary as $row) {
                fputcsv($handle, [
                    $row['name'],
                    ucfirst($row['category']),
                    $row['cups'],
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['TOTAL ALL DRINKS', '', array_sum(array_column($summary, 'cups'))]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function with()
    {
        DrinkOrder::autoCompleteExpiredDeliveries();

        $allFilteredOrders = $this->buildFilteredOrdersQuery()->get();
        $paginatedOrders = $this->buildFilteredOrdersQuery()->paginate($this->perPage);
        $totalCups = $allFilteredOrders->sum(fn($o) => $o->items->sum('quantity'));

        $drinkTotals = [];
        foreach ($allFilteredOrders as $order) {
            foreach ($order->items as $it) {
                $base = \App\Support\CbvhLocale::toBaseDrinkName($it->drinkItem?->name ?? 'Other');
                $category = $it->drinkItem?->category ?? 'drink';
                if (!isset($drinkTotals[$base])) {
                    $drinkTotals[$base] = [
                        'name' => $base,
                        'category' => $category,
                        'cups' => 0,
                    ];
                }
                $drinkTotals[$base]['cups'] += (int) $it->quantity;
            }
        }
        uasort($drinkTotals, fn($a, $b) => $b['cups'] <=> $a['cups']);

        $users = User::with('branch')
            ->orderByRaw('CASE WHEN telegram_chat_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->get();

        return [
            'branches' => Branch::all(),
            'orders' => $paginatedOrders,
            'totalOrders' => $allFilteredOrders->count(),
            'totalCups' => $totalCups,
            'quickCount' => $allFilteredOrders->where('order_type', 'instant')->count(),
            'meetingCount' => $allFilteredOrders->where('order_type', 'meeting')->count(),
            'extendCount' => $allFilteredOrders->where('order_type', 'extend')->count(),
            'drinkTotals' => $drinkTotals,
            'users' => $users,
            'currentHostUrl' => request()->getSchemeAndHttpHost(),
        ];
    }
};
?>

<div wire:poll.8s x-data="{ confirmLogout: false }" class="min-h-screen bg-[#FAF6F3]/80 dark:bg-[#1A1412]/90 text-[#2C211C] dark:text-[#F5EDE7] flex flex-col">
    <!-- Header -->
    <header class="bg-white/95 dark:bg-[#241C19]/95 backdrop-blur-md border-b border-[#E8DDD6] dark:border-[#3D2F29] px-6 py-3.5 flex flex-wrap items-center justify-between gap-4 sticky top-0 z-10 shadow-[0_2px_16px_rgba(158,107,85,0.06)]">
        <div class="flex items-center space-x-3.5">
            <div class="bg-white rounded-xl px-2.5 py-1 border border-[#E8DDD6] shadow-2xs">
                <img src="{{ asset('images/cbvh-logo.png') }}" alt="CBVH" class="h-8 w-auto object-contain" />
            </div>
            <div>
                <h1 class="text-base font-bold leading-tight text-[#2C211C] dark:text-[#F5EDE7]">{{ __('cbvh.admin_title') }}</h1>
                <p class="text-xs text-[#7C6A60] dark:text-[#BBA79D]">{{ __('cbvh.admin_subtitle') }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="$set('activeSection', 'reports')"
                    class="px-3.5 py-2 rounded-xl text-xs font-bold transition cursor-pointer {{ $activeSection === 'reports' ? 'bg-[#9E6B55] text-white' : 'bg-[#F5EDE7] text-[#835440] border border-[#E8DDD6]' }}">
                📊 {{ __('cbvh.tab_tracking_reports') }}
            </button>
            <button wire:click="$set('activeSection', 'team')"
                    class="px-3.5 py-2 rounded-xl text-xs font-bold transition cursor-pointer {{ $activeSection === 'team' ? 'bg-[#9E6B55] text-white' : 'bg-[#F5EDE7] text-[#835440] border border-[#E8DDD6]' }}">
                👥 {{ __('cbvh.tab_team_bot') }}
            </button>

            <a href="{{ route('lang.switch', app()->getLocale() === 'km' ? 'en' : 'km') }}"
               class="px-3 py-2 rounded-xl text-xs font-bold bg-[#FAF6F3] border border-[#E8DDD6] text-[#835440]">
                {{ app()->getLocale() === 'km' ? '🇬🇧 EN' : '🇰🇭 ខ្មែរ' }}
            </a>

            <a href="/barista" class="px-3.5 py-2 rounded-xl bg-white border border-[#E8DDD6] text-[#835440] text-xs font-bold hover:bg-[#F5EDE7]">{{ __('cbvh.barista') }}</a>
            <a href="/" class="px-3.5 py-2 rounded-xl bg-[#2C211C] text-white text-xs font-bold">{{ __('cbvh.mini_app') }} &rarr;</a>

            <button type="button" @click="confirmLogout = true" class="px-3 py-2 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold cursor-pointer">
                {{ __('cbvh.logout') }}
            </button>
        </div>
    </header>

    <!-- Floating Toast Notification (Modern, Non-Intrusive & Auto-Dismissing) -->
    @if($flashMessage)
        <div wire:key="admin-toast-{{ md5($flashMessage . ($flashType ?? 'success') . microtime()) }}"
             x-data="{ show: true }"
             x-init="setTimeout(() => { show = false; setTimeout(() => $wire.set('flashMessage', null), 300); }, 3200)"
             x-show="show"
             x-transition:enter="transition ease-out duration-300 transform"
             x-transition:enter-start="opacity-0 translate-y-4 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-200 transform"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 translate-y-2 scale-95"
             class="fixed bottom-6 right-6 z-50 max-w-md pointer-events-auto">
            <div class="flex items-center space-x-3 px-4 py-3.5 rounded-2xl shadow-2xl backdrop-blur-md border {{ ($flashType ?? 'success') === 'success' ? 'bg-white/95 dark:bg-[#241C19]/95 border-emerald-400 dark:border-emerald-600/70 text-[#2C211C] dark:text-[#F5EDE7]' : 'bg-rose-50/95 dark:bg-rose-950/90 border-rose-400 dark:border-rose-700 text-rose-900 dark:text-rose-100' }}">
                <span class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 text-xs font-black {{ ($flashType ?? 'success') === 'success' ? 'bg-emerald-500 text-white shadow-xs' : 'bg-rose-500 text-white shadow-xs' }}">
                    {{ ($flashType ?? 'success') === 'success' ? '✓' : '✕' }}
                </span>
                <span class="text-xs sm:text-sm font-bold leading-snug flex-1">{{ $flashMessage }}</span>
                <button type="button" @click="show = false; setTimeout(() => $wire.set('flashMessage', null), 300)"
                        class="p-1 text-[#7C6A60] hover:text-[#2C211C] dark:hover:text-white rounded-lg transition cursor-pointer text-base leading-none">
                    &times;
                </button>
            </div>
        </div>
    @endif

    <main class="max-w-7xl mx-auto w-full px-6 py-6 flex-1 space-y-6">

        @if($activeSection === 'reports')
            <!-- Quick Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#E8DDD6] shadow-2xs">
                    <div class="text-[11px] font-bold text-[#7C6A60] uppercase">{{ __('cbvh.total_orders') }}</div>
                    <div class="text-2xl font-black text-[#2C211C] dark:text-[#F5EDE7] mt-1">{{ $totalOrders }}</div>
                </div>
                <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#E8DDD6] shadow-2xs">
                    <div class="text-[11px] font-bold text-[#7C6A60] uppercase">{{ __('cbvh.total_cups') }}</div>
                    <div class="text-2xl font-black text-[#9E6B55] mt-1">{{ $totalCups }}</div>
                </div>
                <div class="bg-amber-50 p-4 rounded-2xl border border-amber-200 shadow-2xs">
                    <div class="text-[11px] font-bold text-amber-800 uppercase">⚡ {{ __('cbvh.quick_drink') }}</div>
                    <div class="text-2xl font-black text-amber-900 mt-1">{{ $quickCount }}</div>
                </div>
                <div class="bg-[#F5EDE7] p-4 rounded-2xl border border-[#E8DDD6] shadow-2xs">
                    <div class="text-[11px] font-bold text-[#835440] uppercase">📅 {{ __('cbvh.meeting_drink') }}</div>
                    <div class="text-2xl font-black text-[#2C211C] mt-1">{{ $meetingCount }}</div>
                </div>
                <div class="bg-purple-50 p-4 rounded-2xl border border-purple-200 shadow-2xs">
                    <div class="text-[11px] font-bold text-purple-800 uppercase">🔄 {{ __('cbvh.reason_spilled') }} / +</div>
                    <div class="text-2xl font-black text-purple-900 mt-1">{{ $extendCount }}</div>
                </div>
            </div>

            <!-- Filter Bar + Export Actions -->
            <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xs space-y-3.5">
                <!-- Row 1: Time Presets + Export Action Buttons -->
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <!-- Period Presets -->
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button type="button" wire:click="setPeriod('today')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer {{ $filterPeriod === 'today' ? 'bg-[#9E6B55] text-white shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#2A201C] text-[#7C6A60] dark:text-[#BBA79D] border border-[#E8DDD6] dark:border-[#3D2F29] hover:bg-[#F5EDE7]' }}">
                            📅 {{ __('cbvh.preset_today') }}
                        </button>
                        <button type="button" wire:click="setPeriod('this_month')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer {{ $filterPeriod === 'this_month' ? 'bg-[#9E6B55] text-white shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#2A201C] text-[#7C6A60] dark:text-[#BBA79D] border border-[#E8DDD6] dark:border-[#3D2F29] hover:bg-[#F5EDE7]' }}">
                            🗓️ {{ __('cbvh.preset_this_month') }} ({{ now()->format('M Y') }})
                        </button>
                        <button type="button" wire:click="setPeriod('last_month')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer {{ $filterPeriod === 'last_month' ? 'bg-[#9E6B55] text-white shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#2A201C] text-[#7C6A60] dark:text-[#BBA79D] border border-[#E8DDD6] dark:border-[#3D2F29] hover:bg-[#F5EDE7]' }}">
                            ⏪ {{ __('cbvh.preset_last_month') }} ({{ now()->subMonthNoOverflow()->format('M Y') }})
                        </button>
                        <button type="button" wire:click="setPeriod('all')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer {{ $filterPeriod === 'all' ? 'bg-[#9E6B55] text-white shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#2A201C] text-[#7C6A60] dark:text-[#BBA79D] border border-[#E8DDD6] dark:border-[#3D2F29] hover:bg-[#F5EDE7]' }}">
                            🌐 {{ __('cbvh.preset_all_time') }}
                        </button>
                    </div>

                    <!-- Export Buttons -->
                    <div class="flex items-center space-x-2 shrink-0">
                        <button wire:click="exportCsv"
                                class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs flex items-center space-x-1.5 transition cursor-pointer">
                            <span>📥</span>
                            <span>{{ __('cbvh.export_csv') }}</span>
                        </button>
                        <button wire:click="exportDrinkTotalsCsv"
                                class="px-4 py-2 bg-[#9E6B55] hover:bg-[#835440] text-white font-bold text-xs rounded-xl shadow-xs flex items-center space-x-1.5 transition cursor-pointer">
                            <span>📊</span>
                            <span>{{ __('cbvh.export_drink_totals') }}</span>
                        </button>
                    </div>
                </div>

                <!-- Row 2: Comprehensive Multi-Filter (Search + Branch + Staff + Type + Status + From Date + To Date) -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-2.5 pt-2 border-t border-[#FAF6F3] dark:border-[#3D2F29]">
                    <!-- Live Search -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">🔍 {{ __('cbvh.search') }}</label>
                        <input type="text" wire:model.live.debounce.250ms="searchQuery" placeholder="{{ __('cbvh.search_placeholder') }}"
                               class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]" />
                    </div>

                    <!-- Branch Filter -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">🏢 {{ __('cbvh.branch') }}</label>
                        <select wire:model.live="filterBranchId" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]">
                            <option value="all">{{ __('cbvh.all_branches') }}</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ \App\Support\CbvhLocale::branch($b->name) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Staff / Requester Filter -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">👤 {{ __('cbvh.col_requester') }}</label>
                        <select wire:model.live="filterUserId" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]">
                            <option value="all">{{ __('cbvh.all_staff') }}</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->branch?->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Order Type Filter -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">📋 {{ __('cbvh.order_type') }}</label>
                        <select wire:model.live="filterType" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]">
                            <option value="all">{{ __('cbvh.all_types') }}</option>
                            <option value="instant">⚡ {{ __('cbvh.quick_drink') }}</option>
                            <option value="meeting">📅 {{ __('cbvh.meeting_drink') }}</option>
                            <option value="extend">🔄 {{ __('cbvh.reason_spilled') }}</option>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">🏷️ {{ __('cbvh.status') }}</label>
                        <select wire:model.live="filterStatus" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]">
                            <option value="all">{{ __('cbvh.all_statuses') }}</option>
                            <option value="approved">{{ __('cbvh.status_approved') }}</option>
                            <option value="preparing">{{ __('cbvh.status_preparing') }}</option>
                            <option value="in_delivery">{{ __('cbvh.status_in_delivery') }}</option>
                            <option value="completed">{{ __('cbvh.status_completed') }}</option>
                            <option value="rejected_by_barista">{{ __('cbvh.status_rejected') }}</option>
                        </select>
                    </div>

                    <!-- From Date -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">📅 {{ __('cbvh.from_date') }}</label>
                        <input type="date" wire:model.live="dateFrom" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]" />
                    </div>

                    <!-- To Date -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">📅 {{ __('cbvh.to_date') }}</label>
                        <input type="date" wire:model.live="dateTo" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]" />
                    </div>
                </div>
            </div>

            <!-- Drink Totals Summary Breakdown -->
            @if(!empty($drinkTotals))
                <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xs space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#FAF6F3] dark:border-[#3D2F29] pb-2.5">
                        <div class="flex items-center space-x-2">
                            <span class="text-base">☕</span>
                            <h3 class="text-xs font-black uppercase tracking-wider text-[#2C211C] dark:text-[#F5EDE7]">
                                {{ __('cbvh.drink_summary_title') }}
                            </h3>
                            <span class="text-[11px] font-bold text-[#9E6B55] bg-[#F5EDE7] dark:bg-[#3A2C26] px-2.5 py-0.5 rounded-full">
                                {{ $totalCups }} {{ __('cbvh.total_cups') }}
                            </span>
                        </div>
                        <label class="inline-flex items-center space-x-2 text-[11px] font-bold text-[#7C6A60] cursor-pointer select-none">
                            <input type="checkbox" wire:model.live="showOptionsInTable" class="rounded text-[#9E6B55] focus:ring-[#9E6B55]" />
                            <span>{{ __('cbvh.show_recipe_details') }}</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-2.5">
                        @foreach($drinkTotals as $baseName => $stat)
                            <div class="p-3 rounded-xl bg-[#FAF6F3] dark:bg-[#1E1715] border border-[#E8DDD6]/60 dark:border-[#3D2F29] flex items-center justify-between shadow-2xs">
                                <div class="min-w-0 pr-2">
                                    <div class="font-black text-xs text-[#2C211C] dark:text-[#F5EDE7] truncate">
                                        {{ \App\Support\CbvhLocale::baseDrink($baseName) }}
                                    </div>
                                    <div class="text-[9px] uppercase tracking-wider text-[#7C6A60]">
                                        {{ $stat['category'] }}
                                    </div>
                                </div>
                                <span class="text-sm font-black px-2.5 py-1 rounded-lg bg-[#9E6B55] text-white shrink-0 shadow-2xs">
                                    {{ $stat['cups'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Report Table -->
            <div class="bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xs overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-[#E8DDD6] bg-[#FAF6F3] text-[#7C6A60] uppercase text-[10px] tracking-wider">
                            <th class="py-3 px-4">{{ __('cbvh.col_order') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_type') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_branch_room') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_requester') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_drinks') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_target_time') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_attachment') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_status') }}</th>
                            <th class="py-3 px-4 text-right">{{ __('cbvh.col_action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#FAF6F3] dark:divide-[#3D2F29]">
                        @forelse($orders as $order)
                            <tr class="hover:bg-[#FAF6F3]/60 transition">
                                <td class="py-3.5 px-4 font-bold whitespace-nowrap">#{{ $order->id }}</td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase
                                        {{ $order->order_type === 'instant' ? 'bg-amber-100 text-amber-800' : ($order->order_type === 'extend' ? 'bg-purple-100 text-purple-800' : 'bg-[#F5EDE7] text-[#835440]') }}">
                                        {{ $order->order_type === 'extend' ? 'EXTEND #' . $order->parent_order_id : $order->order_type }}
                                    </span>
                                    @if($order->extend_reason)
                                        <div class="text-[10px] text-purple-700 mt-0.5">{{ __('cbvh.reason_' . $order->extend_reason) }}</div>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold">{{ \App\Support\CbvhLocale::room($order->room?->name) }}</div>
                                    <div class="text-[10px] text-[#7C6A60]">{{ $order->branch?->code }}</div>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-semibold">{{ $order->user?->name }}</div>
                                    <div class="text-[10px] text-[#7C6A60]">{{ $order->people_count }} {{ __('cbvh.pax') }}</div>
                                </td>
                                <td class="py-3.5 px-4 space-y-0.5 max-w-xs">
                                    @if($showOptionsInTable)
                                        @foreach($order->items as $it)
                                            <div>
                                                <strong>{{ $it->quantity }}×</strong> {{ \App\Support\CbvhLocale::drink($it->drinkItem?->name) }}
                                                @if(!empty($it->selected_options))
                                                    <span class="text-[10px] text-[#7C6A60]">({{ collect($it->selected_options)->map(fn($v) => \App\Support\CbvhLocale::option($v))->implode(', ') }})</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    @else
                                        @php
                                            $groupedDrinks = $order->items->groupBy(fn($it) => \App\Support\CbvhLocale::toBaseDrinkName($it->drinkItem?->name));
                                        @endphp
                                        @foreach($groupedDrinks as $bName => $gItems)
                                            <div class="font-bold text-xs text-[#2C211C] dark:text-[#F5EDE7]">
                                                <span class="text-[#9E6B55] font-black mr-1">{{ $gItems->sum('quantity') }}×</span>
                                                {{ \App\Support\CbvhLocale::baseDrink($bName) }}
                                            </div>
                                        @endforeach
                                    @endif
                                    @if($order->notes)
                                        <div class="text-[10px] italic text-[#9E6B55]">"{{ $order->notes }}"</div>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div>{{ $order->delivery_time ? \App\Support\CbvhLocale::formatDateTime($order->delivery_time) : '-' }}</div>
                                    <div class="text-[10px] text-[#7C6A60]">{{ \App\Support\CbvhLocale::formatDateTime($order->created_at) }}</div>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @if($order->attachment_path)
                                        <a href="{{ Storage::url($order->attachment_path) }}" target="_blank"
                                           class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg bg-[#F5EDE7] text-[#835440] font-bold text-[11px] hover:bg-[#E8CFC1]">
                                            <span>📎</span>
                                            <span class="max-w-[100px] truncate">{{ $order->attachment_name }}</span>
                                        </a>
                                    @else
                                        <span class="text-[#7C6A60]/50">—</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase
                                        {{ $order->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : ($order->status === 'in_delivery' ? 'bg-blue-100 text-blue-800' : (str_contains($order->status, 'rejected') ? 'bg-rose-100 text-rose-800' : 'bg-[#F5EDE7] text-[#835440]')) }}">
                                        {{ __('cbvh.status_' . ($order->status === 'rejected_by_barista' ? 'rejected' : $order->status)) }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    @if($order->status === 'pending_approval')
                                        <button wire:click="sendLegacyToBarista({{ $order->id }})" class="px-2.5 py-1 bg-emerald-600 text-white rounded-lg text-[10px] font-bold cursor-pointer">
                                            Barista
                                        </button>
                                    @elseif(in_array($order->status, ['approved', 'preparing', 'in_delivery']))
                                        <button wire:click="markOrderCompleted({{ $order->id }})" class="px-2.5 py-1 bg-[#FAF6F3] border border-[#E8DDD6] text-[#2C211C] rounded-lg text-[10px] font-bold cursor-pointer hover:bg-[#F5EDE7]">
                                            {{ __('cbvh.close_order') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-10 text-center text-[#7C6A60]">{{ __('cbvh.no_matching_orders') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <!-- Pagination Links & Per-Page Controls -->
                <div class="p-4 border-t border-[#FAF6F3] dark:border-[#3D2F29] flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center space-x-2 text-xs text-[#7C6A60]">
                        <span>{{ __('cbvh.show') }}</span>
                        <select wire:model.live="perPage" class="text-xs py-1 px-2.5 rounded-lg border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-bold text-[#2C211C] dark:text-[#F5EDE7]">
                            <option value="15">15</option>
                            <option value="30">30</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span>{{ __('cbvh.orders_per_page') }}</span>
                    </div>

                    @if($orders->hasPages())
                        <div class="scale-90 sm:scale-100 origin-right">
                            {{ $orders->links() }}
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if($activeSection === 'team')
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <div class="lg:col-span-2 space-y-6">
                    <!-- Create Staff Account Card -->
                    <div class="bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] p-5 shadow-2xs space-y-3">
                        <h2 class="font-bold text-sm">➕ {{ __('cbvh.add_staff') }}</h2>
                        <form wire:submit="createStaffAccount" class="grid grid-cols-1 sm:grid-cols-5 gap-2.5">
                            <input type="text" wire:model="newName" placeholder="{{ __('cbvh.full_name') }}" required
                                   class="text-xs p-2.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3]" />
                            <input type="email" wire:model="newEmail" placeholder="{{ __('cbvh.email') }}" required
                                   class="text-xs p-2.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3]" />
                            <input type="password" wire:model="newPassword" placeholder="{{ __('cbvh.password') }}" required
                                   class="text-xs p-2.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3]" />
                            <select wire:model="newBranchId" class="text-xs p-2.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3] font-semibold">
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}">{{ $b->code }} {{ $b->is_hq ? '(HQ)' : '' }}</option>
                                @endforeach
                            </select>
                            <div class="flex space-x-1.5">
                                <select wire:model="newRole" class="w-full text-xs p-2.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3] font-semibold">
                                    <option value="employee">Staff</option>
                                    <option value="barista">Barista</option>
                                    <option value="admin">Admin</option>
                                </select>
                                <button type="submit" class="px-3.5 py-2.5 bg-[#9E6B55] hover:bg-[#835440] text-white text-xs font-bold rounded-xl shrink-0 cursor-pointer">
                                    Add
                                </button>
                            </div>
                        </form>
                        @error('newEmail') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        @error('newPassword') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                    </div>

                    <!-- Staff Directory -->
                    <div class="bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xs overflow-hidden">
                        <div class="px-5 py-4 border-b border-[#E8DDD6]">
                            <h2 class="font-bold text-sm">👥 {{ __('cbvh.team_title') }}</h2>
                            <p class="text-xs text-[#7C6A60] mt-0.5">{{ __('cbvh.team_subtitle') }}</p>
                        </div>

                        <div class="divide-y divide-[#FAF6F3] dark:divide-[#3D2F29]">
                            @foreach($users as $u)
                                <div class="p-4 flex flex-wrap items-center justify-between gap-3 text-xs">
                                    <div class="min-w-[180px]">
                                        <div class="font-bold text-[#2C211C] dark:text-[#F5EDE7] flex items-center space-x-1.5">
                                            <span>{{ $u->name }}</span>
                                            @if($u->telegram_chat_id)
                                                <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-full text-[10px] font-bold">{{ __('cbvh.telegram_linked') }}</span>
                                            @else
                                                <span class="px-2 py-0.5 bg-slate-100 text-slate-500 rounded-full text-[10px]">{{ __('cbvh.not_linked') }}</span>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-[#7C6A60]">
                                            {{ $u->email }} {{ $u->telegram_username ? '• @'.$u->telegram_username : '' }}
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <select wire:change="updateUserRole({{ $u->id }}, $event.target.value)"
                                                class="px-2.5 py-1.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3] font-bold text-xs text-[#2C211C]">
                                            @foreach([
                                                'employee' => 'Employee',
                                                'barista' => 'Barista',
                                                'admin' => 'Admin',
                                            ] as $rKey => $rLabel)
                                                <option value="{{ $rKey }}" @selected($u->role === $rKey)>{{ $rLabel }}</option>
                                            @endforeach
                                        </select>

                                        <select wire:change="updateUserBranch({{ $u->id }}, $event.target.value)"
                                                class="px-2.5 py-1.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3] font-semibold text-xs text-[#2C211C]">
                                            @foreach($branches as $b)
                                                <option value="{{ $b->id }}" @selected($u->branch_id === $b->id)>{{ $b->code }} {{ $b->is_hq ? '(HQ)' : '' }}</option>
                                            @endforeach
                                        </select>

                                        @if($u->telegram_chat_id)
                                            <button wire:click="sendTestTelegram({{ $u->id }})"
                                                    class="px-2.5 py-1.5 rounded-xl bg-[#F5EDE7] hover:bg-[#9E6B55] text-[#835440] hover:text-white font-bold text-[11px] transition cursor-pointer">
                                                {{ __('cbvh.test_ping') }}
                                            </button>
                                            <button wire:click="unlinkTelegram({{ $u->id }})"
                                                    class="px-2.5 py-1.5 rounded-xl bg-rose-50 text-rose-600 font-bold text-[11px] cursor-pointer">
                                                {{ __('cbvh.unlink') }}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-6">
                    <div class="bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] p-5 shadow-2xs space-y-3">
                        <h3 class="font-bold text-sm">⏰ {{ __('cbvh.working_hours_rule') }}</h3>
                        <p class="text-xs text-[#7C6A60] leading-relaxed">{{ __('cbvh.working_hours_desc') }}</p>
                        <button wire:click="toggleWorkingHoursRule"
                                class="w-full py-3 px-4 rounded-xl font-bold text-xs transition flex items-center justify-between cursor-pointer {{ $enforceWorkingHours ? 'bg-[#9E6B55] text-white' : 'bg-amber-500 text-white' }}">
                            <span>{{ $enforceWorkingHours ? '🔒 ' . __('cbvh.enforced_hours') : '🧪 ' . __('cbvh.testing_24_7') }}</span>
                            <span class="underline text-[11px]">{{ __('cbvh.switch') }}</span>
                        </button>
                    </div>


                </div>
            </div>
        @endif

    </main>

    <x-logout-confirm-modal mode="form" />
</div>
