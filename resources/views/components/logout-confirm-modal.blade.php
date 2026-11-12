@props(['mode' => 'form'])

<div x-show="confirmLogout"
     x-cloak
     @click.self="confirmLogout = false"
     @keydown.escape.window="confirmLogout = false"
     class="cbvh-backdrop-fade fixed inset-0 bg-[#2C211C]/55 backdrop-blur-xs z-50 flex items-center justify-center p-4">
    <div class="cbvh-fade-slide bg-white dark:bg-[#241C19] w-full max-w-xs rounded-3xl p-5 border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xl space-y-4 text-center">
        <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 text-rose-600 flex items-center justify-center text-2xl mx-auto">
            🚪
        </div>
        <div class="space-y-1">
            <h3 class="text-base font-bold text-[#2C211C] dark:text-[#F5EDE7]">
                {{ __('cbvh.logout_confirm_title') }}
            </h3>
            <p class="text-xs text-[#7C6A60] dark:text-[#BBA79D]">
                {{ __('cbvh.logout_confirm_desc') }}
            </p>
        </div>

        <div class="grid grid-cols-2 gap-2 pt-1">
            <button type="button" @click="confirmLogout = false"
                    class="py-2.5 px-3 rounded-xl bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7] font-bold text-xs cursor-pointer hover:bg-[#F5EDE7] transition">
                {{ __('cbvh.cancel') }}
            </button>

            @if($mode === 'livewire')
                <button type="button" wire:click="logout(true)" @click="confirmLogout = false"
                        class="py-2.5 px-3 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-xs cursor-pointer transition">
                    {{ __('cbvh.logout_confirm_yes') }}
                </button>
            @else
                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <button type="submit"
                            class="w-full py-2.5 px-3 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-xs cursor-pointer transition">
                        {{ __('cbvh.logout_confirm_yes') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>

