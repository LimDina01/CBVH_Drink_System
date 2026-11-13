<?php

use Livewire\Component;
use App\Models\Branch;
use App\Models\DrinkOrder;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    public $viewFilter = 'all'; // 'all', 'instant', 'meeting'
    public $flashMessage = null;
    public int $lastKnownOrderId = 0;

    public function mount()
    {
        $this->lastKnownOrderId = (int) (DrinkOrder::max('id') ?? 0);
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
                app(TelegramService::class)->sendMessage(
                    $order->user->telegram_chat_id,
                    "☕ <b>Order #{$orderId} Accepted!</b> Barista is preparing your full drink package now."
                );
            }

            $this->flashMessage = "#{$orderId} — " . __('cbvh.status_preparing');
        }
    }

    public function markInDelivery($orderId)
    {
        $order = DrinkOrder::find($orderId);
        if ($order && $order->status === 'preparing') {
            $order->update([
                'status' => 'in_delivery',
                'ready_at' => now(),
                'in_delivery_at' => now(),
            ]);

            app(TelegramService::class)->notifyUserOrderInDelivery($order);
            $this->flashMessage = "#{$orderId} — " . __('cbvh.status_in_delivery');
        }
    }

    public function markReady($orderId)
    {
        $this->markInDelivery($orderId);
    }

    public function with()
    {
        DrinkOrder::autoCompleteExpiredDeliveries();

        $orders = DrinkOrder::whereIn('status', ['approved', 'preparing', 'in_delivery'])
            ->with(['branch', 'room', 'items.drinkItem'])
            ->orderByRaw('COALESCE(delivery_time, created_at) ASC')
            ->orderBy('id', 'asc')
            ->get();

        $instantOrders = $orders->whereIn('order_type', ['instant', 'extend']);
        $meetingOrders = $orders->where('order_type', 'meeting');

        $approvedOrders = $orders->where('status', 'approved');
        $newCount = $approvedOrders->count();
        $latestApproved = $approvedOrders->sortByDesc('id')->first();

        if ($latestApproved && $latestApproved->id > $this->lastKnownOrderId) {
            $this->lastKnownOrderId = (int) $latestApproved->id;
            $orderType = ($latestApproved->order_type === 'meeting') ? 'meeting' : 'quick';
            $this->dispatch('new-barista-order', orderType: $orderType);
        }

        $completedToday = DrinkOrder::where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        return [
            'instantOrders' => $instantOrders,
            'meetingOrders' => $meetingOrders,
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
        menuOpen: false,
        confirmLogout: false,
        audioCtx: null,
        wakeLock: null,
        init() {
            this.keepScreenAwake();
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    this.keepScreenAwake();
                }
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
        unlockAudio() {
            this.keepScreenAwake();
            try {
                if (!this.audioCtx) {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (Ctx) this.audioCtx = new Ctx();
                }
                if (this.audioCtx && this.audioCtx.state === 'suspended') {
                    this.audioCtx.resume().catch(() => {});
                }
            } catch (e) {}
        },
        playDingDong() {
            try {
                this.unlockAudio();
                const ctx = this.audioCtx;
                if (!ctx) return;
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
        playOrderAlert(type = 'quick') {
            if (!this.soundOn) return;
            this.unlockAudio();
            this.playDingDong();

            setTimeout(() => {
                try {
                    const voiceSrc = (type === 'meeting') 
                        ? '{{ asset('sounds/voice-meeting.mp3') }}' 
                        : '{{ asset('sounds/voice-quick.mp3') }}';
                    const voice = new Audio(voiceSrc);
                    voice.play().catch(() => {});
                } catch (e) {}
            }, 600);
        }
     }"
     @click.window="unlockAudio()"
     @touchstart.window="unlockAudio()"
     @new-barista-order.window="playOrderAlert($event.detail?.orderType || 'quick')"
     class="min-h-screen bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] flex flex-col touch-manipulation select-none">

    <!-- Header -->
    <header class="bg-white dark:bg-[#241C19] border-b-2 border-[#E8DDD6] dark:border-[#3D2F29] px-5 py-3.5 flex flex-wrap items-center justify-between gap-3 sticky top-0 z-10 shadow-xs">
        <div class="flex items-center space-x-3">
            <img src="{{ asset('images/cbvh-mark.png') }}" alt="CBVH" class="w-11 h-11 rounded-xl object-cover border border-[#E8DDD6]" />
            <div>
                <h1 class="text-xl font-black text-[#2C211C] dark:text-[#F5EDE7] leading-tight">{{ __('cbvh.barista_title') }}</h1>
                <p class="text-sm font-bold text-emerald-700">{{ __('cbvh.barista_subtitle') }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 relative">
            <!-- Sound Toggle & Voice Test Buttons -->
            <div class="flex items-center space-x-1.5">
                <button type="button" @click="soundOn = !soundOn; if(soundOn) playOrderAlert('quick')"
                        class="px-3.5 py-2 rounded-xl text-sm font-bold border transition cursor-pointer flex items-center space-x-1.5"
                        :class="soundOn ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-slate-100 border-slate-300 text-slate-600'"
                        title="{{ __('cbvh.sound_on') }}">
                    <span x-show="soundOn">🔊 {{ __('cbvh.sound_on') }}</span>
                    <span x-show="!soundOn" x-cloak>🔇 {{ __('cbvh.muted') }}</span>
                </button>

                <button type="button" @click="playOrderAlert('quick')"
                        class="px-2.5 py-2 rounded-xl text-xs font-bold bg-[#FAF6F3] dark:bg-[#3A2C26] border border-[#E8DDD6] dark:border-[#523F36] text-[#835440] dark:text-[#E8CFC1] hover:bg-[#F5EDE7] cursor-pointer"
                        title="{{ __('cbvh.test_quick_alert') }}">
                    ⚡ {{ __('cbvh.filter_quick') }}
                </button>
                <button type="button" @click="playOrderAlert('meeting')"
                        class="px-2.5 py-2 rounded-xl text-xs font-bold bg-[#FAF6F3] dark:bg-[#3A2C26] border border-[#E8DDD6] dark:border-[#523F36] text-[#835440] dark:text-[#E8CFC1] hover:bg-[#F5EDE7] cursor-pointer"
                        title="{{ __('cbvh.test_meeting_alert') }}">
                    🗓️ {{ __('cbvh.filter_meeting') }}
                </button>
            </div>

            <div class="text-sm bg-[#F5EDE7] dark:bg-[#3A2C26] text-[#835440] dark:text-[#E8CFC1] px-3.5 py-2 rounded-xl font-bold border border-[#E8DDD6]">
                {{ __('cbvh.done_today', ['count' => $completedToday]) }}
            </div>

            <!-- Manager / Advanced Menu (prevents accidental taps by barista) -->
            <div class="relative" @click.outside="menuOpen = false">
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
    <main class="max-w-7xl mx-auto w-full p-4 md:p-5 flex-1">
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
    </main>

    <x-logout-confirm-modal mode="form" />
</div>
