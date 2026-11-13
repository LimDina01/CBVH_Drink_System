<?php

use Livewire\Component;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

new class extends Component
{
    public string $loginId = '';
    public string $email = ''; // alias for backwards compatibility
    public string $password = '';
    public ?string $telegramInitData = null;
    public ?string $errorMessage = null;
    public bool $checkingTelegram = false;

    public function attemptTelegramAutoLogin(?string $initData = null): void
    {
        if (empty($initData)) {
            return;
        }

        $this->telegramInitData = $initData;
        $user = app(TelegramService::class)->resolveUserFromInitData($initData);

        if ($user) {
            Auth::login($user, remember: true);
            session()->regenerate();
            $this->redirectAfterLogin($user->role);
        }
    }

    public function login(): void
    {
        $this->errorMessage = null;

        $input = trim($this->loginId ?: $this->email);

        if (empty($input) || empty($this->password)) {
            $this->errorMessage = __('cbvh.invalid_credentials');
            return;
        }

        $user = User::whereRaw('LOWER(employee_id) = ?', [strtolower($input)])
            ->orWhereRaw('LOWER(email) = ?', [strtolower($input)])
            ->first();

        if (!$user || !Hash::check($this->password, $user->password)) {
            $this->errorMessage = __('cbvh.invalid_credentials');
            return;
        }

        Auth::login($user, remember: true);

        session()->regenerate();

        if (!empty($this->telegramInitData)) {
            app(TelegramService::class)->bindTelegramToUser($user, $this->telegramInitData);
        }

        $this->redirectAfterLogin($user->role);
    }

    protected function redirectAfterLogin(string $role): void
    {
        $default = match ($role) {
            'admin' => '/admin',
            'barista' => '/barista',
            default => '/',
        };

        $this->redirectIntended($default);
    }
};
?>

<div x-data x-init="if (window.Telegram?.WebApp?.initData) { $wire.attemptTelegramAutoLogin(window.Telegram.WebApp.initData); }"
     class="min-h-screen flex flex-col items-center justify-center p-5 cbvh-safe-header">

    <!-- Top Language & Theme Bar -->
    <div class="w-full max-w-sm flex items-center justify-end space-x-2 mb-4">
        <a href="{{ route('lang.switch', app()->getLocale() === 'km' ? 'en' : 'km') }}"
           class="px-3 py-1.5 rounded-full text-xs font-bold bg-white dark:bg-[#241C19] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#835440] dark:text-[#E8CFC1] shadow-2xs hover:border-[#9E6B55] transition">
            {{ app()->getLocale() === 'km' ? '🇬🇧 English' : '🇰🇭 ខ្មែរ' }}
        </a>

        <button type="button" onclick="window.toggleCbvhTheme()" title="{{ __('cbvh.switch_theme') }}"
                class="w-8 h-8 rounded-full border border-[#E8DDD6] dark:border-[#3D2F29] bg-white dark:bg-[#241C19] flex items-center justify-center text-[#835440] dark:text-[#E8CFC1] hover:border-[#9E6B55] transition cursor-pointer">
            <svg class="w-4 h-4 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41m11.32-11.32 1.41-1.41"/>
            </svg>
            <svg class="w-4 h-4 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </button>
    </div>

    <!-- Luxury Login Card -->
    <div class="w-full max-w-sm bg-white dark:bg-[#241C19] rounded-3xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-[0_8px_32px_rgba(158,107,85,0.10)] p-6 space-y-5">
        <div class="text-center space-y-2">
            <img src="{{ asset('images/cbvh-logo.png') }}" alt="CBVH" class="h-28 w-auto max-w-full mx-auto object-contain" />
            <h1 class="text-lg font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ __('cbvh.login_title') }}</h1>
            <p class="text-xs text-[#7C6A60] dark:text-[#BBA79D]">{{ __('cbvh.login_subtitle') }}</p>
        </div>

        @if($telegramInitData)
            <div class="p-3 rounded-xl bg-[#F5EDE7] dark:bg-[#3A2C26] border border-[#E8DDD6] dark:border-[#4A3830] text-[11px] font-medium text-[#835440] dark:text-[#E8CFC1] text-center">
                🔗 {{ __('cbvh.login_telegram_hint') }}
            </div>
        @endif

        @if($errorMessage)
            <div class="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold text-center">
                {{ $errorMessage }}
            </div>
        @endif

        <form wire:submit="login" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-[#7C6A60] dark:text-[#BBA79D] mb-1.5">{{ __('cbvh.login_id') }}</label>
                <input type="text" wire:model="loginId" required autofocus placeholder="{{ __('cbvh.login_id_placeholder') }}"
                       class="w-full text-sm px-4 py-3 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] focus:border-[#9E6B55] focus:outline-hidden" />
                @error('loginId') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#7C6A60] dark:text-[#BBA79D] mb-1.5">{{ __('cbvh.password') }}</label>
                <input type="password" wire:model="password" required placeholder="••••••••"
                       class="w-full text-sm px-4 py-3 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] focus:border-[#9E6B55] focus:outline-hidden" />
                @error('password') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                <p class="text-[11px] text-[#7C6A60] dark:text-[#BBA79D] mt-1.5 leading-tight">
                    💡 {{ __('cbvh.forgot_password_hint') }}
                </p>
            </div>

            <button type="submit" wire:loading.attr="disabled"
                    class="w-full py-3.5 bg-[#9E6B55] hover:bg-[#835440] text-white font-bold text-sm rounded-2xl shadow-md transition cursor-pointer">
                <span wire:loading.remove wire:target="login">{{ __('cbvh.sign_in') }}</span>
                <span wire:loading wire:target="login">{{ __('cbvh.signing_in') }}</span>
            </button>
        </form>
    </div>
</div>
