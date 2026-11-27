<?php

use Livewire\Component;
use App\Models\Branch;
use App\Models\DrinkOrder;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

new class extends Component
{
    public $viewFilter = 'all'; // 'all', 'instant', 'meeting'
    public bool $showHistory = false;
    public string $historyFilter = 'all'; // 'all', 'cancelled', 'completed'
    public $flashMessage = null;
    public int $lastKnownOrderId = 0;
    public array $dismissedCancelledIds = [];
    public array $seenCancelledIds = [];

    protected function dismissedCacheKey(): string
    {
        return 'barista_dismissed_cancelled_' . (Auth::id() ?? 0);
    }

    public function mount()
    {
        $this->lastKnownOrderId = (int) (DrinkOrder::max('id') ?? 0);
        $this->dismissedCancelledIds = Cache::get($this->dismissedCacheKey(), []);
        $this->seenCancelledIds = DrinkOrder::where('status', 'cancelled')
            ->where('updated_at', '>=', now()->subMinutes(60))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function dismissCancelledAlert(int $orderId): void
    {
        if (! in_array($orderId, $this->dismissedCancelledIds, true)) {
            $this->dismissedCancelledIds[] = $orderId;
            Cache::put($this->dismissedCacheKey(), $this->dismissedCancelledIds, now()->addHours(12));
        }
    }

    public function acceptOrder($orderId)
    {
        $order = DrinkOrder::find($orderId);
        if ($order && $order->status === 'approved') {
            if ($order->order_type === 'meeting' && $order->delivery_time && $order->delivery_time->isAfter(today()->endOfDay())) {
                return;
            }

            $order->update([
                'status' => 'preparing',
                'barista_id' => Auth::id(),
                'barista_accepted_at' => now(),
            ]);

            if ($order->user && $order->user->telegram_chat_id) {
                try {
                    app(TelegramService::class)->sendMessage(
                        $order->user->telegram_chat_id,
                        "☕ <b>Order #{$orderId} Accepted!</b> Barista is preparing your full drink package now."
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Telegram accept notification failed: " . $e->getMessage());
                }
            }

            $this->flashMessage = "#{$orderId} — " . __('cbvh.status_preparing');
        }
    }

    public function markInDelivery($orderId)
    {
        $order = DrinkOrder::find($orderId);
        if ($order && in_array($order->status, ['preparing', 'in_delivery'])) {
            if ($order->order_type === 'recurring') {
                $order->update([
                    'status' => 'completed',
                    'ready_at' => now(),
                    'in_delivery_at' => now(),
                    'completed_at' => now(),
                ]);
                $this->flashMessage = "#{$orderId} — " . __('cbvh.status_completed');
                return;
            }

            $order->update([
                'status' => 'in_delivery',
                'ready_at' => now(),
                'in_delivery_at' => now(),
            ]);

            app(TelegramService::class)->notifyUserOrderInDelivery($order);
            $this->flashMessage = "#{$orderId} — " . __('cbvh.status_in_delivery');
        }
    }

    public function completeOrder($orderId)
    {
        $order = DrinkOrder::find($orderId);
        if ($order && in_array($order->status, ['approved', 'preparing', 'in_delivery'])) {
            $order->update([
                'status' => 'completed',
                'barista_id' => $order->barista_id ?? Auth::id(),
                'barista_accepted_at' => $order->barista_accepted_at ?? now(),
                'ready_at' => $order->ready_at ?? now(),
                'in_delivery_at' => $order->in_delivery_at ?? now(),
                'completed_at' => now(),
            ]);
            $this->flashMessage = "#{$orderId} — " . __('cbvh.status_completed');
        }
    }

    public function markReady($orderId)
    {
        $this->markInDelivery($orderId);
    }

    public function with()
    {
        DrinkOrder::autoCompleteExpiredDeliveries();
        try {
            app(\App\Services\RecurringOrderService::class)->dispatchDueOrders();
        } catch (\Throwable $e) {}

        $orders = DrinkOrder::whereIn('status', ['approved', 'preparing', 'in_delivery'])
            ->with(['branch', 'room', 'items.drinkItem'])
            ->orderByRaw('COALESCE(delivery_time, created_at) ASC')
            ->orderBy('id', 'asc')
            ->get();

        $instantOrders = $orders->whereIn('order_type', ['instant', 'extend', 'recurring']);
        $meetingOrders = $orders->where('order_type', 'meeting');

        $approvedOrders = $orders->where('status', 'approved');
        $newCount = $approvedOrders->count();
        $latestApproved = $approvedOrders->sortByDesc('id')->first();

        if ($latestApproved && $latestApproved->id > $this->lastKnownOrderId) {
            $this->lastKnownOrderId = (int) $latestApproved->id;
            $orderType = ($latestApproved->order_type === 'meeting') ? 'meeting' : 'quick';
            $this->dispatch('new-barista-order', orderType: $orderType, orderId: (int) $latestApproved->id);
        }

        $completedToday = DrinkOrder::where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        $cancelledTodayCount = DrinkOrder::whereIn('status', ['cancelled', 'rejected_by_barista'])
            ->whereDate('updated_at', today())
            ->count();

        $todayHistoryOrders = collect();
        if ($this->showHistory) {
            $historyQuery = DrinkOrder::with(['user', 'branch', 'room', 'items.drinkItem'])
                ->where(function ($q) {
                    $q->where(function ($cq) {
                        $cq->where('status', 'completed')->whereDate('completed_at', today());
                    })->orWhere(function ($rq) {
                        $rq->whereIn('status', ['cancelled', 'rejected_by_barista'])->whereDate('updated_at', today());
                    });
                });

            if ($this->historyFilter === 'cancelled') {
                $historyQuery->whereIn('status', ['cancelled', 'rejected_by_barista']);
            } elseif ($this->historyFilter === 'completed') {
                $historyQuery->where('status', 'completed');
            }

            $todayHistoryOrders = $historyQuery
                ->orderByDesc('updated_at')
                ->limit(80)
                ->get();
        }

        $recentCancelledOrders = DrinkOrder::where('status', 'cancelled')
            ->where('updated_at', '>=', now()->subMinutes(60))
            ->when(! empty($this->dismissedCancelledIds), fn ($q) => $q->whereNotIn('id', $this->dismissedCancelledIds))
            ->with(['branch', 'room', 'items.drinkItem'])
            ->orderByDesc('updated_at')
            ->get();

        $latestNewCancelled = null;
        foreach ($recentCancelledOrders as $cOrder) {
            if (! in_array((int) $cOrder->id, $this->seenCancelledIds, true)) {
                $this->seenCancelledIds[] = (int) $cOrder->id;
                if (! $latestNewCancelled) {
                    $latestNewCancelled = $cOrder;
                }
            }
        }
        if ($latestNewCancelled) {
            $cancelType = ($latestNewCancelled->order_type === 'meeting') ? 'cancelled_meeting' : 'cancelled_quick';
            $this->dispatch('cancelled-barista-order', orderType: $cancelType, orderId: (int) $latestNewCancelled->id);
        }

        return [
            'instantOrders' => $instantOrders,
            'meetingOrders' => $meetingOrders,
            'recentCancelledOrders' => $recentCancelledOrders,
            'todayHistoryOrders' => $todayHistoryOrders,
            'cancelledTodayCount' => $cancelledTodayCount,
            'newCount' => $newCount,
            'preparingCount' => $orders->where('status', 'preparing')->count(),
            'deliveryCount' => $orders->where('status', 'in_delivery')->count(),
            'completedToday' => $completedToday,
            'currentUser' => Auth::user(),
        ];
    }
};
?>

<div wire:poll.5s
     x-data="{
        soundOn: true,
        audioUnlocked: false,
        menuOpen: false,
        confirmLogout: false,
        audioCtx: null,
        wakeLock: null,
        activeSource: null,
        lastAlertAt: 0,
        rawBuffers: {},
        decodedBuffers: {},
        soundUrls: {
            quick: '/sounds/voice-quick.wav',
            meeting: '/sounds/voice-meeting.wav',
            cancelled_quick: '/sounds/voice-cancelled-quick.mp3',
            cancelled_meeting: '/sounds/voice-cancelled-meeting.mp3',
        },
        init() {
            this.keepScreenAwake();
            this.preloadSounds();
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    this.keepScreenAwake();
                }
            });
        },
        preloadSounds() {
            Object.entries(this.soundUrls).forEach(([key, url]) => {
                fetch(url)
                    .then(r => r.ok ? r.arrayBuffer() : null)
                    .then(buf => { if (buf) this.rawBuffers[key] = buf; })
                    .catch(() => {});
            });
        },
        async keepScreenAwake() {
            try {
                if ('wakeLock' in navigator && !this.wakeLock) {
                    this.wakeLock = await navigator.wakeLock.request('screen');
                    this.wakeLock.addEventListener('release', () => {
                        this.wakeLock = null;
                    });
                }
            } catch (e) {}
        },
        async ensureAudioCtx() {
            try {
                if (!this.audioCtx) {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (Ctx) this.audioCtx = new Ctx();
                }
                if (!this.audioCtx) return null;
                if (this.audioCtx.state === 'suspended') {
                    await this.audioCtx.resume();
                }
                if (this.audioCtx.state === 'running') {
                    this.audioUnlocked = true;
                }
                return this.audioCtx;
            } catch (e) {
                return null;
            }
        },
        unlockAudio() {
            this.keepScreenAwake();
            if (this.audioUnlocked) return;
            this.ensureAudioCtx();
        },
        playCancelAlertBeep() {
            try {
                if (!this.audioCtx) {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (Ctx) this.audioCtx = new Ctx();
                }
                const ctx = this.audioCtx;
                if (!ctx) return;
                if (ctx.state === 'suspended') ctx.resume().catch(() => {});
                const now = ctx.currentTime;
                [784, 523.25, 784].forEach((freq, idx) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'triangle';
                    osc.frequency.setValueAtTime(freq, now + idx * 0.18);
                    gain.gain.setValueAtTime(0.28, now + idx * 0.18);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.18 + 0.35);
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start(now + idx * 0.18);
                    osc.stop(now + idx * 0.18 + 0.35);
                });
            } catch (e) {}
        },
        playDingDong() {
            try {
                if (!this.audioCtx) {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (Ctx) this.audioCtx = new Ctx();
                }
                const ctx = this.audioCtx;
                if (!ctx) return;
                if (ctx.state === 'suspended') ctx.resume().catch(() => {});
                const now = ctx.currentTime;
                [587.33, 880].forEach((freq, idx) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(freq, now + idx * 0.16);
                    gain.gain.setValueAtTime(0.24, now + idx * 0.16);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.16 + 0.45);
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start(now + idx * 0.16);
                    osc.stop(now + idx * 0.16 + 0.45);
                });
            } catch (e) {}
        },
        wasAlreadyPlayed(key) {
            if (!key) return false;
            try {
                const storageKey = 'cbvh_barista_played_' + new Date().toISOString().slice(0, 10);
                const list = JSON.parse(localStorage.getItem(storageKey) || '[]');
                if (list.includes(key)) return true;
                list.push(key);
                localStorage.setItem(storageKey, JSON.stringify(list.slice(-200)));
                return false;
            } catch (e) {
                return false;
            }
        },
        async playOrderAlert(type = 'quick', orderId = null) {
            if (!this.soundOn) return;
            if (orderId) {
                const isCancel = (type === 'cancelled_quick' || type === 'cancelled_meeting');
                const dedupKey = (isCancel ? 'cancel:' : 'new:') + orderId;
                if (this.wasAlreadyPlayed(dedupKey)) return;
            }

            const nowMs = Date.now();
            if (orderId && (nowMs - this.lastAlertAt) < 1200) return;
            this.lastAlertAt = nowMs;

            const isCancel = (type === 'cancelled_quick' || type === 'cancelled_meeting');
            const fallback = () => isCancel ? this.playCancelAlertBeep() : this.playDingDong();

            try {
                const ctx = await this.ensureAudioCtx();
                if (ctx && ctx.state === 'running') {
                    if (!this.decodedBuffers[type]) {
                        let raw = this.rawBuffers[type];
                        if (!raw && this.soundUrls[type]) {
                            const res = await fetch(this.soundUrls[type]);
                            if (res.ok) raw = await res.arrayBuffer();
                        }
                        if (raw) {
                            this.decodedBuffers[type] = await ctx.decodeAudioData(raw.slice(0));
                        }
                    }

                    if (this.decodedBuffers[type]) {
                        if (this.activeSource) {
                            try { this.activeSource.stop(); } catch (e) {}
                        }
                        const src = ctx.createBufferSource();
                        src.buffer = this.decodedBuffers[type];
                        src.connect(ctx.destination);
                        this.activeSource = src;
                        src.onended = () => { if (this.activeSource === src) this.activeSource = null; };
                        src.start(0);
                        return;
                    }
                }

                const audioMap = {
                    quick: 'barista-sound-quick',
                    meeting: 'barista-sound-meeting',
                    cancelled_quick: 'barista-sound-cancelled-quick',
                    cancelled_meeting: 'barista-sound-cancelled-meeting',
                };
                const el = document.getElementById(audioMap[type] || 'barista-sound-quick');
                if (el) {
                    el.muted = false;
                    el.pause();
                    el.currentTime = 0;
                    await el.play();
                    return;
                }
                fallback();
            } catch (e) {
                console.warn('Direct audio play failed, falling back to chime:', e);
                fallback();
            }
        }
     }"
     @click.window="unlockAudio()"
     @touchstart.window="unlockAudio()"
     @new-barista-order.window="playOrderAlert($event.detail?.orderType || $event.detail?.[0]?.orderType || 'quick', $event.detail?.orderId || $event.detail?.[0]?.orderId || null)"
     @cancelled-barista-order.window="playOrderAlert($event.detail?.orderType || $event.detail?.[0]?.orderType || 'cancelled_quick', $event.detail?.orderId || $event.detail?.[0]?.orderId || null)"
     class="min-h-screen bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] flex flex-col touch-manipulation select-none">

    <!-- Preloaded Sound Elements (Fallback, preserved across Livewire poll morphs) -->
    <div wire:ignore class="hidden">
        <audio id="barista-sound-quick" preload="auto" src="/sounds/voice-quick.wav"></audio>
        <audio id="barista-sound-meeting" preload="auto" src="/sounds/voice-meeting.wav"></audio>
        <audio id="barista-sound-cancelled-quick" preload="auto" src="/sounds/voice-cancelled-quick.mp3"></audio>
        <audio id="barista-sound-cancelled-meeting" preload="auto" src="/sounds/voice-cancelled-meeting.mp3"></audio>
    </div>

    <!-- Header -->
    <header class="bg-white dark:bg-[#241C19] border-b-2 border-[#E8DDD6] dark:border-[#3D2F29] px-3 py-2.5 md:px-5 md:py-3.5 flex flex-col md:flex-row md:items-center md:justify-between gap-2 md:gap-3 sticky top-0 z-10 shadow-xs">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center space-x-2.5 md:space-x-3 min-w-0">
                <img src="{{ asset('images/cbvh-mark.png') }}" alt="CBVH" class="w-9 h-9 md:w-11 md:h-11 rounded-xl object-cover border border-[#E8DDD6] shrink-0" />
                <div class="min-w-0">
                    <h1 class="text-base md:text-xl font-black text-[#2C211C] dark:text-[#F5EDE7] leading-tight truncate">{{ __('cbvh.barista_title') }}</h1>
                    <p class="text-xs md:text-sm font-bold text-emerald-700">{{ __('cbvh.barista_subtitle') }}</p>
                </div>
            </div>

            <!-- Mobile settings button sits neatly on top-right -->
            <div class="relative md:hidden shrink-0" @click.outside="menuOpen = false">
                <button type="button" @click="menuOpen = !menuOpen" title="{{ __('cbvh.settings_menu') }}"
                        class="w-9 h-9 rounded-xl bg-[#FAF6F3] border border-[#E8DDD6] text-[#7C6A60] hover:border-[#9E6B55] flex items-center justify-center text-sm font-bold cursor-pointer">
                    ⚙️
                </button>
                <div x-show="menuOpen" x-cloak
                     class="absolute right-0 mt-2 w-52 bg-white dark:bg-[#241C19] border-2 border-[#E8DDD6] rounded-2xl shadow-xl p-2 space-y-1 z-30">
                    <div class="px-2 py-1 text-[11px] font-bold uppercase text-[#7C6A60]">{{ __('cbvh.settings_menu') }}</div>
                    <a href="{{ route('lang.switch', app()->getLocale() === 'km' ? 'en' : 'km') }}"
                       class="block w-full text-left px-3 py-2 rounded-xl text-sm font-bold hover:bg-[#FAF6F3] text-[#2C211C]">
                        {{ app()->getLocale() === 'km' ? '🇬🇧 EN' : '🇰🇭 ខ្មែរ' }}
                    </a>
                    @if($currentUser?->isAdmin())
                        <a href="/admin" class="block w-full text-left px-3 py-2 rounded-xl text-sm font-bold hover:bg-[#FAF6F3] text-[#835440]">{{ __('cbvh.admin') }}</a>
                    @endif
                    <a href="/" class="block w-full text-left px-3 py-2 rounded-xl text-sm font-bold hover:bg-[#FAF6F3] text-[#835440]">{{ __('cbvh.mini_app') }}</a>
                    <div class="border-t border-[#E8DDD6] my-1"></div>
                    <button type="button" @click="menuOpen = false; confirmLogout = true" class="w-full text-left px-3 py-2 rounded-xl text-sm font-bold text-rose-700 hover:bg-rose-50 cursor-pointer">
                        🚪 {{ __('cbvh.logout') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-1.5 md:gap-2 relative">
            <!-- Sound Toggle & Voice Test Buttons -->
            <div class="flex items-center space-x-1.5">
                <button type="button" @click="soundOn = !soundOn; if(soundOn) playOrderAlert('quick')"
                        class="px-2.5 py-1.5 md:px-3.5 md:py-2 rounded-xl text-xs md:text-sm font-bold border transition cursor-pointer flex items-center space-x-1"
                        :class="soundOn ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-slate-100 border-slate-300 text-slate-600'"
                        title="{{ __('cbvh.sound_on') }}">
                    <span x-show="soundOn">🔊 {{ __('cbvh.sound_on') }}</span>
                    <span x-show="!soundOn" x-cloak>🔇 {{ __('cbvh.muted') }}</span>
                </button>

                <button type="button" @click="playOrderAlert('quick')"
                        class="hidden sm:inline-flex px-2.5 py-2 rounded-xl text-xs font-bold bg-[#FAF6F3] dark:bg-[#3A2C26] border border-[#E8DDD6] dark:border-[#523F36] text-[#835440] dark:text-[#E8CFC1] hover:bg-[#F5EDE7] cursor-pointer"
                        title="{{ __('cbvh.test_quick_alert') }}">
                    ⚡ {{ __('cbvh.filter_quick') }}
                </button>
                <button type="button" @click="playOrderAlert('meeting')"
                        class="hidden sm:inline-flex px-2.5 py-2 rounded-xl text-xs font-bold bg-[#FAF6F3] dark:bg-[#3A2C26] border border-[#E8DDD6] dark:border-[#523F36] text-[#835440] dark:text-[#E8CFC1] hover:bg-[#F5EDE7] cursor-pointer"
                        title="{{ __('cbvh.test_meeting_alert') }}">
                    🗓️ {{ __('cbvh.filter_meeting') }}
                </button>
            </div>

            <div class="text-xs md:text-sm bg-[#F5EDE7] dark:bg-[#3A2C26] text-[#835440] dark:text-[#E8CFC1] px-2.5 py-1.5 md:px-3.5 md:py-2 rounded-xl font-bold border border-[#E8DDD6]">
                {{ __('cbvh.done_today', ['count' => $completedToday]) }}
            </div>

            <button type="button" wire:click="$toggle('showHistory')"
                    class="text-xs md:text-sm px-2.5 py-1.5 md:px-3.5 md:py-2 rounded-xl font-bold border transition cursor-pointer flex items-center gap-1 {{ $showHistory ? 'bg-[#9E6B55] text-white border-[#835440] shadow-xs' : 'bg-white dark:bg-[#241C19] text-[#2C211C] dark:text-[#F5EDE7] border-[#E8DDD6] hover:bg-[#FAF6F3]' }}">
                @if($showHistory)
                    <span>{{ __('cbvh.barista_back_to_orders') }}</span>
                @else
                    <span>📜 {{ __('cbvh.barista_history_btn') }}</span>
                    @if($cancelledTodayCount > 0)
                        <span class="px-1.5 py-0.5 rounded-full bg-rose-600 text-white text-[10px] md:text-[11px] font-black">{{ $cancelledTodayCount }} ❌</span>
                    @endif
                @endif
            </button>

            <!-- Manager / Advanced Menu (Tablet/Desktop) -->
            <div class="relative hidden md:block" @click.outside="menuOpen = false">
                <button type="button" @click="menuOpen = !menuOpen" title="{{ __('cbvh.settings_menu') }}"
                        class="w-10 h-10 rounded-xl bg-[#FAF6F3] border border-[#E8DDD6] text-[#7C6A60] hover:border-[#9E6B55] flex items-center justify-center text-base font-bold cursor-pointer">
                    ⚙️
                </button>
                <div x-show="menuOpen" x-cloak
                     class="absolute right-0 mt-2 w-52 bg-white dark:bg-[#241C19] border-2 border-[#E8DDD6] rounded-2xl shadow-xl p-2 space-y-1 z-30">
                    <div class="px-2 py-1 text-[11px] font-bold uppercase text-[#7C6A60]">{{ __('cbvh.settings_menu') }}</div>
                    <a href="{{ route('lang.switch', app()->getLocale() === 'km' ? 'en' : 'km') }}"
                       class="block w-full text-left px-3 py-2 rounded-xl text-sm font-bold hover:bg-[#FAF6F3] text-[#2C211C]">
                        {{ app()->getLocale() === 'km' ? '🇬🇧 EN' : '🇰🇭 ខ្មែរ' }}
                    </a>
                    @if($currentUser?->isAdmin())
                        <a href="/admin" class="block w-full text-left px-3 py-2 rounded-xl text-sm font-bold hover:bg-[#FAF6F3] text-[#835440]">{{ __('cbvh.admin') }}</a>
                    @endif
                    <a href="/" class="block w-full text-left px-3 py-2 rounded-xl text-sm font-bold hover:bg-[#FAF6F3] text-[#835440]">{{ __('cbvh.mini_app') }}</a>
                    <div class="border-t border-[#E8DDD6] my-1"></div>
                    <button type="button" @click="menuOpen = false; confirmLogout = true" class="w-full text-left px-3 py-2 rounded-xl text-sm font-bold text-rose-700 hover:bg-rose-50 cursor-pointer">
                        🚪 {{ __('cbvh.logout') }}
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Floating Toast Notification (Modern & Smooth) -->
    @if($flashMessage)
        <div wire:key="barista-toast-{{ md5($flashMessage . microtime()) }}"
             x-data="{ show: true }"
             x-init="setTimeout(() => { show = false; setTimeout(() => $wire.set('flashMessage', null), 300); }, 2200)"
             x-show="show"
             x-transition:enter="transition ease-out duration-300 transform"
             x-transition:enter-start="opacity-0 translate-y-4 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-200 transform"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 translate-y-2 scale-95"
             class="fixed bottom-6 right-6 z-50 pointer-events-auto">
            <div @click="show = false; setTimeout(() => $wire.set('flashMessage', null), 300)"
                 class="px-5 py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-2xl text-base font-black shadow-2xl flex items-center space-x-2.5 cursor-pointer transition select-none">
                <span class="w-6 h-6 rounded-full bg-white/20 flex items-center justify-center text-xs">✓</span>
                <span>{{ $flashMessage }}</span>
            </div>
        </div>
    @endif

    <!-- Split Kitchen Queues -->
    <main class="max-w-7xl mx-auto w-full p-3 md:p-5 flex-1">
        <div x-show="soundOn && !audioUnlocked" x-cloak
             @click="unlockAudio(); playDingDong()"
             class="mb-4 px-4 py-3 rounded-2xl bg-amber-100 border-2 border-amber-400 text-amber-950 font-black text-sm flex items-center justify-between gap-3 cursor-pointer shadow-xs hover:bg-amber-200 transition">
            <span>🔇 Browser locked autoplay — tap anywhere once to enable Barista sound alerts</span>
            <span class="px-3 py-1 rounded-xl bg-amber-600 text-white text-xs font-bold shrink-0">🔊 Enable Sound</span>
        </div>
        @if($recentCancelledOrders->isNotEmpty())
            <div class="mb-5 space-y-3">
                @foreach($recentCancelledOrders as $cOrder)
                    <div wire:key="barista-cancelled-alert-{{ $cOrder->id }}"
                         class="bg-rose-600 text-white rounded-3xl p-4 md:p-5 border-4 border-rose-300 shadow-xl flex flex-wrap items-center justify-between gap-4">
                        <div class="space-y-1">
                            <div class="text-lg md:text-xl font-black tracking-tight flex items-center gap-2">
                                <span>❌</span>
                                <span>{{ __('cbvh.barista_cancelled_banner', ['id' => $cOrder->id]) }}</span>
                            </div>
                            <div class="text-sm font-bold text-rose-100">
                                {{ $cOrder->order_type === 'meeting' ? '📅 ' . __('cbvh.barista_cancelled_meeting_msg') : '⚡ ' . __('cbvh.barista_cancelled_quick_msg') }}
                                • {{ $cOrder->branch?->name ?? 'CBVH' }} — {{ $cOrder->room?->name ?? ($cOrder->location_detail ?: 'Office') }}
                                @if($cOrder->delivery_time)
                                    • 🕒 {{ $cOrder->delivery_time->format('h:i A') }}
                                @endif
                            </div>
                            <div class="text-xs font-semibold text-rose-100 flex flex-wrap gap-2 pt-0.5">
                                @foreach($cOrder->items as $it)
                                    <span class="px-2 py-0.5 rounded-lg bg-rose-800/60">
                                        {{ $it->quantity }}× {{ $it->drinkItem?->name ?? $it->drink_name_snapshot }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                        <button type="button"
                                wire:click="dismissCancelledAlert({{ $cOrder->id }})"
                                class="px-5 py-3 rounded-2xl bg-white text-rose-700 hover:bg-rose-50 font-black text-base shadow-md cursor-pointer shrink-0 transition">
                            👍 {{ __('cbvh.btn_got_it') }}
                        </button>
                    </div>
                @endforeach
            </div>
        @endif

        @if($showHistory)
            <!-- Read-Only Today's History View -->
            <section class="space-y-4">
                <div class="bg-white dark:bg-[#241C19] rounded-2xl border-2 border-[#E8DDD6] dark:border-[#3D2F29] p-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-black text-[#2C211C] dark:text-[#F5EDE7]">📜 {{ __('cbvh.barista_history_title') }}</h2>
                        <p class="text-xs font-semibold text-[#7C6A60]">{{ __('cbvh.barista_history_sub') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="$set('historyFilter', 'all')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold border cursor-pointer transition {{ $historyFilter === 'all' ? 'bg-[#9E6B55] text-white border-[#835440]' : 'bg-[#FAF6F3] text-[#7C6A60] border-[#E8DDD6]' }}">
                            {{ __('cbvh.barista_history_all') }}
                        </button>
                        <button type="button" wire:click="$set('historyFilter', 'cancelled')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold border cursor-pointer transition {{ $historyFilter === 'cancelled' ? 'bg-rose-600 text-white border-rose-700' : 'bg-rose-50 text-rose-700 border-rose-200' }}">
                            ❌ {{ __('cbvh.barista_history_cancelled') }} ({{ $cancelledTodayCount }})
                        </button>
                        <button type="button" wire:click="$set('historyFilter', 'completed')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold border cursor-pointer transition {{ $historyFilter === 'completed' ? 'bg-emerald-600 text-white border-emerald-700' : 'bg-emerald-50 text-emerald-800 border-emerald-200' }}">
                            ✅ {{ __('cbvh.barista_history_completed') }} ({{ $completedToday }})
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @forelse($todayHistoryOrders as $hOrder)
                        @php
                            $isCancelled = in_array($hOrder->status, ['cancelled', 'rejected_by_barista'], true);
                            $typeIcon = match($hOrder->order_type) {
                                'meeting' => '📅 ' . __('cbvh.meeting_drink'),
                                'extend' => '➕ ' . __('cbvh.btn_order_more_spilled'),
                                'recurring' => '📆 ' . __('cbvh.recurring_badge'),
                                default => '⚡ ' . __('cbvh.quick_drink'),
                            };
                        @endphp
                        <div wire:key="barista-history-card-{{ $hOrder->id }}"
                             class="bg-white dark:bg-[#241C19] rounded-2xl border-2 p-4 space-y-2.5 {{ $isCancelled ? 'border-rose-300 bg-rose-50/40 dark:bg-rose-950/20' : 'border-[#E8DDD6] dark:border-[#3D2F29]' }}">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-base font-black text-[#2C211C] dark:text-[#F5EDE7]">#{{ $hOrder->id }}</span>
                                        <span class="text-xs font-bold text-[#835440]">{{ $typeIcon }}</span>
                                    </div>
                                    <div class="text-xs font-semibold text-[#7C6A60]">
                                        {{ $hOrder->branch?->name ?? 'CBVH' }} — {{ $hOrder->room?->name ?? ($hOrder->location_detail ?: 'Office') }}
                                        • 👤 {{ $hOrder->user?->name ?? 'Staff' }}
                                    </div>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-black uppercase shrink-0 {{ $isCancelled ? 'bg-rose-600 text-white' : 'bg-emerald-100 text-emerald-800' }}">
                                    {{ $isCancelled ? '❌ ' . __('cbvh.status_cancelled') : '✅ ' . __('cbvh.status_completed') }}
                                </span>
                            </div>

                            <div class="flex flex-wrap gap-1.5">
                                @foreach($hOrder->items as $it)
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-bold bg-[#FAF6F3] dark:bg-[#1E1715] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7]">
                                        {{ $it->quantity }}× {{ \App\Support\CbvhLocale::drink($it->drinkItem?->name ?? $it->drink_name_snapshot) }}
                                    </span>
                                @endforeach
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] font-semibold text-[#7C6A60] pt-1 border-t border-[#FAF6F3] dark:border-[#3D2F29]">
                                <span>🕒 {{ ($hOrder->completed_at ?? $hOrder->updated_at ?? $hOrder->created_at)?->format('h:i A') }}</span>
                                @if($isCancelled && $hOrder->approver_rejection_reason)
                                    <span class="text-rose-700 font-bold">{{ $hOrder->approver_rejection_reason }}</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="md:col-span-2 py-12 text-center bg-white dark:bg-[#241C19] rounded-3xl border-2 border-dashed border-[#E8DDD6]">
                            <div class="text-3xl mb-2">📜</div>
                            <div class="text-base font-bold text-[#7C6A60]">{{ __('cbvh.barista_history_empty') }}</div>
                        </div>
                    @endforelse
                </div>
            </section>
        @else
        <!-- Queue View Switcher (Phone-friendly 1-row tabs) -->
        <div class="mb-3 md:mb-4 grid grid-cols-3 md:flex md:flex-wrap items-center gap-1.5 md:gap-2">
            <button type="button" wire:click="$set('viewFilter', 'all')"
                    class="px-2 py-2 md:px-3.5 rounded-xl text-[11px] md:text-sm font-black border transition cursor-pointer text-center truncate {{ $viewFilter === 'all' ? 'bg-[#2C211C] text-white border-[#2C211C] shadow-xs' : 'bg-white dark:bg-[#241C19] text-[#7C6A60] border-[#E8DDD6] hover:bg-[#FAF6F3]' }}">
                {{ __('cbvh.filter_both') }} ({{ count($instantOrders) + count($meetingOrders) }})
            </button>
            <button type="button" wire:click="$set('viewFilter', 'instant')"
                    class="px-2 py-2 md:px-3.5 rounded-xl text-[11px] md:text-sm font-black border transition cursor-pointer flex items-center justify-center gap-1 truncate {{ $viewFilter === 'instant' ? 'bg-amber-500 text-white border-amber-600 shadow-xs' : 'bg-amber-50 text-amber-900 border-amber-200 hover:bg-amber-100' }}">
                <span class="truncate">⚡ {{ __('cbvh.filter_quick') }}</span>
                <span class="px-1.5 py-0.5 rounded-full text-[10px] md:text-[11px] shrink-0 {{ $viewFilter === 'instant' ? 'bg-white/25 text-white' : 'bg-amber-200 text-amber-950' }}">{{ count($instantOrders) }}</span>
            </button>
            <button type="button" wire:click="$set('viewFilter', 'meeting')"
                    class="px-2 py-2 md:px-3.5 rounded-xl text-[11px] md:text-sm font-black border transition cursor-pointer flex items-center justify-center gap-1 truncate {{ $viewFilter === 'meeting' ? 'bg-[#9E6B55] text-white border-[#835440] shadow-xs' : 'bg-[#F5EDE7] text-[#835440] border-[#E8DDD6] hover:bg-[#EDE0D7]' }}">
                <span class="truncate">📅 {{ __('cbvh.filter_meeting') }}</span>
                <span class="px-1.5 py-0.5 rounded-full text-[10px] md:text-[11px] shrink-0 {{ $viewFilter === 'meeting' ? 'bg-white/25 text-white' : 'bg-[#E8DDD6] text-[#2C211C]' }}">{{ count($meetingOrders) }}</span>
            </button>
        </div>

        <div class="grid grid-cols-1 {{ $viewFilter === 'all' ? 'md:grid-cols-2' : '' }} gap-5 md:gap-6 items-start">

            @if($viewFilter === 'all' || $viewFilter === 'instant')
                <section class="space-y-4">
                    <div class="flex items-center justify-between bg-amber-100 border-2 border-amber-300 rounded-2xl px-4 py-3">
                        <div>
                            <h2 class="text-lg font-black text-amber-950">⚡ {{ __('cbvh.section_instant_title') }}</h2>
                            <p class="text-sm font-semibold text-amber-800">{{ __('cbvh.section_instant_sub') }}</p>
                        </div>
                        <span class="px-3.5 py-1 rounded-full bg-amber-500 text-white font-black text-base">{{ count($instantOrders) }}</span>
                    </div>

                    @forelse($instantOrders as $order)
                        @include('components.barista._order-card', ['order' => $order])
                    @empty
                        <div class="py-12 text-center bg-white dark:bg-[#241C19] rounded-3xl border-2 border-dashed border-[#E8DDD6]">
                            <div class="text-3xl mb-2">⚡</div>
                            <div class="text-base font-bold text-[#7C6A60]">{{ __('cbvh.no_quick_orders') }}</div>
                        </div>
                    @endforelse
                </section>
            @endif

            @if($viewFilter === 'all' || $viewFilter === 'meeting')
                <section class="space-y-4">
                    <div class="flex items-center justify-between bg-[#F5EDE7] border-2 border-[#9E6B55]/40 rounded-2xl px-4 py-3">
                        <div>
                            <h2 class="text-lg font-black text-[#2C211C]">📅 {{ __('cbvh.section_meeting_title') }}</h2>
                            <p class="text-sm font-semibold text-[#835440]">{{ __('cbvh.section_meeting_sub') }}</p>
                        </div>
                        <span class="px-3.5 py-1 rounded-full bg-[#9E6B55] text-white font-black text-base">{{ count($meetingOrders) }}</span>
                    </div>

                    @forelse($meetingOrders as $order)
                        @include('components.barista._order-card', ['order' => $order])
                    @empty
                        <div class="py-12 text-center bg-white dark:bg-[#241C19] rounded-3xl border-2 border-dashed border-[#E8DDD6]">
                            <div class="text-3xl mb-2">📅</div>
                            <div class="text-base font-bold text-[#7C6A60]">{{ __('cbvh.no_meeting_orders') }}</div>
                        </div>
                    @endforelse
                </section>
            @endif

        </div>
        @endif
    </main>

    <x-logout-confirm-modal mode="form" />
</div>
