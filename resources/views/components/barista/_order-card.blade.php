@php
    $isFutureMeeting = $order->order_type === 'meeting'
        && $order->delivery_time
        && $order->delivery_time->isAfter(today()->endOfDay());
    $isAsapQuick = in_array($order->order_type, ['instant', 'extend']) && $order->estimated_wait_minutes !== null;
    $isLaterTodayQuick = $order->order_type === 'instant' && $order->estimated_wait_minutes === null;
@endphp
<div wire:key="barista-order-{{ $order->id }}"
     class="bg-white dark:bg-[#241C19] rounded-3xl border-4 {{ $order->status === 'in_delivery' ? 'border-blue-500 opacity-85' : ($isFutureMeeting ? 'border-[#D8C8BE] opacity-85' : ($order->status === 'preparing' ? 'border-[#9E6B55]' : 'border-amber-400')) }} shadow-md flex flex-col justify-between overflow-hidden transition-colors">

    <!-- Status Color Banner -->
    <div class="px-5 py-3 flex items-center justify-between {{ $order->status === 'in_delivery' ? 'bg-blue-600 text-white' : ($order->status === 'preparing' ? 'bg-[#9E6B55] text-white' : 'bg-amber-400 text-[#2C211C]') }}">
        <span class="text-base font-black tracking-wide uppercase">
            @if($order->status === 'approved')
                🔔 {{ __('cbvh.banner_new') }}
            @elseif($order->status === 'preparing')
                👨‍🍳 {{ __('cbvh.banner_making') }}
            @else
                🚚 {{ __('cbvh.banner_delivery') }}
            @endif
        </span>
        <div class="flex items-center space-x-2">
            @if($order->order_type === 'extend')
                <span class="px-2.5 py-0.5 rounded-full bg-purple-600 text-white text-sm font-black">
                    🔄 {{ __('cbvh.extra_badge') }}
                </span>
            @endif
            @if($order->order_type === 'meeting' && $order->delivery_time)
                <span class="px-2.5 py-0.5 rounded-full text-sm font-black {{ $order->delivery_time->isToday() ? 'bg-emerald-600 text-white' : 'bg-white/90 text-[#2C211C]' }}">
                    {{ $order->delivery_time->isToday() ? __('cbvh.today_badge') : __('cbvh.upcoming_badge') }}
                </span>
            @endif
            <span class="text-base font-black opacity-90">#{{ $order->id }}</span>
        </div>
    </div>

    <!-- Branch, Room & Delivery Time (Only what Barista needs) -->
    <div class="px-5 pt-4 pb-3 border-b-2 border-[#FAF6F3] dark:border-[#3D2F29] flex items-center justify-between gap-2">
        <div class="space-y-1">
            @if($order->branch && !$order->branch->is_hq)
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg bg-purple-100 border border-purple-300 text-purple-900 text-sm font-black">
                    🚚 {{ __('cbvh.send_to_branch') }}: {{ \App\Support\CbvhLocale::branch($order->branch->name) }}
                </span>
            @elseif($order->branch)
                <div class="text-sm font-bold text-[#9E6B55]">
                    🏢 {{ \App\Support\CbvhLocale::branch($order->branch->name) }}
                </div>
            @endif
            <div class="text-xl font-black text-[#2C211C] dark:text-[#F5EDE7]">📍 {{ \App\Support\CbvhLocale::room($order->room?->name) }}</div>
            <div class="text-base font-bold text-[#7C6A60]">
                {{ \App\Support\CbvhLocale::floor($order->room?->floor) }}
            </div>
        </div>
        <div class="text-right shrink-0 bg-[#FAF6F3] dark:bg-[#1A1412] px-3.5 py-2 rounded-xl border border-[#E8DDD6] space-y-0.5">
            @if($isAsapQuick)
                <div class="text-sm font-black uppercase text-amber-700">
                    ⚡ {{ __('cbvh.barista_asap_badge') }}
                </div>
                <div class="text-sm font-bold text-[#7C6A60]">
                    {{ __('cbvh.ordered_at') }}: <span class="font-black text-[#835440] dark:text-[#E8CFC1]">{{ \App\Support\CbvhLocale::formatTime($order->created_at) }}</span>
                </div>
            @elseif($isLaterTodayQuick)
                <div class="text-xs font-black uppercase text-[#7C6A60]">
                    🕒 {{ __('cbvh.quick_later_today') }}
                </div>
                <div class="text-base font-black text-[#835440] dark:text-[#E8CFC1]">
                    {{ $order->delivery_time ? \App\Support\CbvhLocale::formatTime($order->delivery_time) : __('cbvh.now') }}
                </div>
            @else
                <div class="text-xs font-black uppercase text-[#7C6A60]">
                    📅 {{ __('cbvh.meeting_time') }}
                </div>
                <div class="text-base font-black text-[#835440] dark:text-[#E8CFC1]">
                    {{ $order->delivery_time ? \App\Support\CbvhLocale::formatDateTime($order->delivery_time) : __('cbvh.now') }}
                </div>
            @endif
        </div>
    </div>

    <!-- Drink Items -->
    <div class="p-5 space-y-3 flex-1">
        @if($order->extend_reason)
            <div class="px-3 py-2 rounded-xl bg-purple-50 border border-purple-200 text-purple-900 text-sm font-bold">
                ⚠️ {{ __('cbvh.reason') }}: {{ __('cbvh.reason_' . $order->extend_reason) }}
            </div>
        @endif

        @foreach($order->items as $it)
            <div class="p-3.5 rounded-2xl bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] space-y-2">
                <div class="flex items-center space-x-3">
                    <span class="w-9 h-9 rounded-full bg-[#9E6B55] text-white font-black text-base flex items-center justify-center shrink-0">
                        {{ $it->quantity }}×
                    </span>
                    <span class="font-black text-lg text-[#2C211C] dark:text-[#F5EDE7] leading-snug">
                        {{ \App\Support\CbvhLocale::drink($it->drinkItem?->name) }}
                    </span>
                </div>

                @if(!empty($it->selected_options))
                    <div class="flex flex-wrap gap-1.5 pl-12">
                        @foreach($it->selected_options as $optKey => $optVal)
                            @php
                                $label = match($optKey) {
                                    'sugar' => '🍯 ' . __('cbvh.opt_sugar'),
                                    'coffee' => '☕ ' . __('cbvh.opt_coffee'),
                                    'ice' => '🧊 ' . __('cbvh.opt_ice'),
                                    'milk' => '🥛 ' . __('cbvh.opt_milk'),
                                    default => ucfirst($optKey),
                                };
                            @endphp
                            <span class="px-3 py-1 rounded-xl text-sm font-bold bg-white dark:bg-[#241C19] text-[#2C211C] dark:text-[#F5EDE7] border border-[#E8DDD6] dark:border-[#4A3830] shadow-2xs">
                                {{ $label }}: <span class="text-[#9E6B55] dark:text-[#E8CFC1]">{{ \App\Support\CbvhLocale::option($optVal) }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        @if($order->notes)
            <div class="p-3 rounded-2xl bg-amber-50 border-2 border-amber-200 text-amber-900 text-base font-bold">
                💬 {{ __('cbvh.note_label') }}: "{{ $order->notes }}"
            </div>
        @endif
    </div>

    <!-- Package Action Button (Strictly Step 1 -> Step 2 -> Done) -->
    <div class="p-5 pt-2 space-y-2">
        @if($isFutureMeeting && $order->status === 'approved')
            <div class="w-full py-3.5 px-4 bg-[#F5EDE7] border-2 border-[#D8C8BE] text-[#7C6A60] font-black text-base rounded-2xl text-center">
                🔒 {{ __('cbvh.future_meeting_locked') }}
            </div>
        @elseif($order->status === 'approved')
            <button wire:click="acceptOrder({{ $order->id }})"
                    class="w-full py-4 bg-[#9E6B55] hover:bg-[#835440] active:scale-[0.98] text-white font-black text-lg rounded-2xl shadow-md transition cursor-pointer touch-manipulation">
                👍 {{ __('cbvh.btn_start_making') }}
            </button>
        @elseif($order->status === 'preparing')
            <button wire:click="markInDelivery({{ $order->id }})"
                    class="w-full py-4 bg-blue-600 hover:bg-blue-700 active:scale-[0.98] text-white font-black text-lg rounded-2xl shadow-md transition cursor-pointer touch-manipulation">
                ✅ {{ __('cbvh.btn_out_for_delivery') }}
            </button>
        @elseif($order->status === 'in_delivery')
            <div class="p-4 rounded-2xl bg-blue-50 border-2 border-blue-300 text-center text-base font-black text-blue-900">
                🚚 {{ __('cbvh.delivery_done_badge') }}
            </div>
        @endif
    </div>
</div>
