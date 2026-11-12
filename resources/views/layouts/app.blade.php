<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? __('cbvh.app_name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://telegram.org/js/telegram-web-app.js"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <script>
        window.applyCbvhTheme = function(theme) {
            const isDark = theme === 'dark';
            document.documentElement.classList.toggle('dark', isDark);
            localStorage.setItem('cbvh_theme', theme);

            if (window.Telegram && window.Telegram.WebApp) {
                const tg = window.Telegram.WebApp;
                try {
                    tg.setHeaderColor(isDark ? '#231B18' : '#FFFFFF');
                    tg.setBackgroundColor(isDark ? '#1A1412' : '#FAF6F3');
                } catch (e) {}
            }
        };

        window.toggleCbvhTheme = function() {
            const isDark = document.documentElement.classList.contains('dark');
            window.applyCbvhTheme(isDark ? 'light' : 'dark');
        };

        (function() {
            const saved = localStorage.getItem('cbvh_theme');
            window.applyCbvhTheme(saved === 'dark' ? 'dark' : 'light');
        })();

        document.addEventListener('DOMContentLoaded', () => {
            if (window.Telegram && window.Telegram.WebApp) {
                const tg = window.Telegram.WebApp;
                tg.ready();
                tg.expand();

                // Step 1: Prevent accidental downward pull inside content from collapsing the Mini App
                if (typeof tg.isVersionAtLeast === 'function' && tg.isVersionAtLeast('7.7') && typeof tg.disableVerticalSwipes === 'function') {
                    tg.disableVerticalSwipes();
                }

                // Step 2: Edge-to-edge fullscreen on mobile Telegram (Bot API 8.0+) with safe-area syncing
                const syncSafeAreas = () => {
                    const root = document.documentElement.style;
                    if (tg.safeAreaInset) {
                        root.setProperty('--cbvh-safe-top', (tg.safeAreaInset.top || 0) + 'px');
                        root.setProperty('--cbvh-safe-bottom', (tg.safeAreaInset.bottom || 0) + 'px');
                    }
                    if (tg.contentSafeAreaInset) {
                        root.setProperty('--cbvh-content-safe-top', (tg.contentSafeAreaInset.top || 0) + 'px');
                    }
                };
                syncSafeAreas();

                if (typeof tg.onEvent === 'function') {
                    tg.onEvent('safeAreaChanged', syncSafeAreas);
                    tg.onEvent('contentSafeAreaChanged', syncSafeAreas);
                    tg.onEvent('fullscreenChanged', syncSafeAreas);
                }

                if (typeof tg.isVersionAtLeast === 'function' && tg.isVersionAtLeast('8.0') && typeof tg.requestFullscreen === 'function') {
                    const mobilePlatforms = ['ios', 'android', 'android_x'];
                    if (!tg.platform || mobilePlatforms.includes(tg.platform)) {
                        if (!tg.isFullscreen) {
                            tg.requestFullscreen();
                        }
                    }
                }

                const saved = localStorage.getItem('cbvh_theme');
                window.applyCbvhTheme(saved === 'dark' ? 'dark' : 'light');

                if (tg.initDataUnsafe && tg.initDataUnsafe.user) {
                    window.telegramUser = tg.initDataUnsafe.user;
                }
            }
        });
    </script>
</head>
<body class="h-full cbvh-diamond-bg text-[#2C211C] dark:text-[#F5EDE7] antialiased selection:bg-[#9E6B55] selection:text-white font-sans">
    <div class="min-h-full flex flex-col">
        {{ $slot }}
    </div>

    @livewireScripts
</body>
</html>
