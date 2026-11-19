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

        // Single-Device Lock: If user is ALREADY bound to another Telegram account, reject unauthorized hijacking
        if (!empty($this->telegramInitData) && !empty($user->telegram_chat_id)) {
            $telegramService = app(TelegramService::class);
            $parsed = $telegramService->validateInitData($this->telegramInitData);
            $incomingChatId = isset($parsed['user']['id']) ? (string) $parsed['user']['id'] : null;

            if ($incomingChatId && $incomingChatId !== (string) $user->telegram_chat_id) {
                // Notify the genuine user on their original phone
                $telegramService->notifyUnauthorizedBindAttempt($user, $parsed['user']['username'] ?? null);

                $this->errorMessage = __('cbvh.device_locked_error');
                return;
            }
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

            <div x-data="{ showPass: false }">
                <label class="block text-xs font-semibold text-[#7C6A60] dark:text-[#BBA79D] mb-1.5">{{ __('cbvh.password') }}</label>
                <div class="relative flex items-center">
                    <input :type="showPass ? 'text' : 'password'" wire:model="password" required placeholder="••••••••"
                           class="w-full text-sm pl-4 pr-11 py-3 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] focus:border-[#9E6B55] focus:outline-hidden" />
                    <button type="button" @click="showPass = !showPass" class="absolute right-3.5 p-1 text-[#7C6A60] hover:text-[#9E6B55] dark:text-[#BBA79D] dark:hover:text-[#E8CFC1] transition cursor-pointer flex items-center justify-center">
                        <svg x-show="!showPass" class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        <svg x-show="showPass" x-cloak class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                        </svg>
                    </button>
                </div>
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
