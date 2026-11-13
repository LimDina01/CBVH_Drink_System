<?php

namespace App\Services;

use App\Models\User;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected ?string $botToken;
    protected string $apiUrl;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token', env('TELEGRAM_BOT_TOKEN'));
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    /**
     * Build an HTTP client configured with a cross-platform CA bundle for SSL verification.
     */
    protected function httpClient(): PendingRequest
    {
        return Http::withOptions([
            'verify' => CaBundle::getSystemCaRootBundlePath(),
        ]);
    }

    /**
     * Fetch the bot's own profile from Telegram to verify connectivity and token validity.
     */
    public function getMe(): ?array
    {
        if (empty($this->botToken)) {
            return null;
        }

        try {
            $response = $this->httpClient()->get("{$this->apiUrl}/getMe");
            return $response->successful() ? $response->json() : null;
        } catch (\Exception $e) {
            Log::error("TelegramService getMe failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Register a Telegram webhook URL for receiving bot updates.
     */
    public function setWebhook(string $url): ?array
    {
        if (empty($this->botToken)) {
            return null;
        }

        try {
            $response = $this->httpClient()->post("{$this->apiUrl}/setWebhook", [
                'url' => $url,
            ]);
            return $response->json();
        } catch (\Exception $e) {
            Log::error("TelegramService setWebhook failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch current webhook configuration from Telegram.
     */
    public function getWebhookInfo(): ?array
    {
        if (empty($this->botToken)) {
            return null;
        }

        try {
            $response = $this->httpClient()->get("{$this->apiUrl}/getWebhookInfo");
            return $response->successful() ? $response->json() : null;
        } catch (\Exception $e) {
            Log::error("TelegramService getWebhookInfo failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find an existing staff user already linked to this Telegram account.
     */
    public function findLinkedTelegramUser(string $chatId, ?string $username = null): ?User
    {
        $user = User::where('telegram_chat_id', $chatId)->first();
        if (!$user && $username) {
            $user = User::where('telegram_username', $username)->first();
            if ($user) {
                $user->update(['telegram_chat_id' => $chatId]);
            }
        }

        return $user;
    }

    /**
     * Validate Telegram initData and resolve a linked staff User (or null if first-time login needed).
     */
    public function resolveUserFromInitData(string $initData): ?User
    {
        $parsed = $this->validateInitData($initData);
        if (!$parsed || empty($parsed['user']['id'])) {
            return null;
        }

        return $this->findLinkedTelegramUser(
            (string) $parsed['user']['id'],
            $parsed['user']['username'] ?? null
        );
    }

    /**
     * Bind a validated Telegram initData identity to an authenticated staff User.
     */
    public function bindTelegramToUser(User $user, string $initData): bool
    {
        $parsed = $this->validateInitData($initData);
        if (!$parsed || empty($parsed['user']['id'])) {
            return false;
        }

        $chatId = (string) $parsed['user']['id'];
        $username = $parsed['user']['username'] ?? null;

        // Single-Device Lock: If user is ALREADY bound to a different Telegram account, refuse to overwrite!
        if (!empty($user->telegram_chat_id) && (string) $user->telegram_chat_id !== $chatId) {
            Log::warning("Blocked unauthorized Telegram re-bind for user {$user->id}. Existing: {$user->telegram_chat_id}, Attempted: {$chatId}");
            return false;
        }

        // Ensure one Telegram account is only linked to this active staff account
        User::where('telegram_chat_id', $chatId)
            ->where('id', '!=', $user->id)
            ->update([
                'telegram_chat_id' => null,
                'telegram_username' => null,
            ]);

        if ($username) {
            User::where('telegram_username', $username)
                ->where('id', '!=', $user->id)
                ->update([
                    'telegram_chat_id' => null,
                    'telegram_username' => null,
                ]);
        }

        $user->update([
            'telegram_chat_id' => $chatId,
            'telegram_username' => $username ?? $user->telegram_username,
        ]);

        return true;
    }

    /**
     * Unlink both chat ID and username so Telegram auto-login will not immediately re-authenticate.
     */
    public function unlinkUser(User $user): void
    {
        $user->update([
            'telegram_chat_id' => null,
            'telegram_username' => null,
        ]);
    }

    /**
     * Send a security warning to the genuine user's Telegram device when an unauthorized login attempt is blocked.
     */
    public function notifyUnauthorizedBindAttempt(User $user, ?string $attemptedUsername = null): void
    {
        if (empty($user->telegram_chat_id)) {
            return;
        }

        $empId = $user->employee_id ?: $user->name;
        $usernameInfo = $attemptedUsername ? " (@{$attemptedUsername})" : '';
        $message = "🚨 <b>CBVH Security Alert</b>\n\n"
                 . "Someone attempted to sign in to your staff account (<b>{$empId}</b>) using another Telegram account{$usernameInfo}.\n\n"
                 . "🔒 <b>Action Taken:</b> The sign-in was blocked because your account is securely locked to this device.\n\n"
                 . "If this was not you, please notify HR or System Admin immediately to reset your password.";

        $this->sendMessage($user->telegram_chat_id, $message);
    }

    /**
     * Send a standard message with optional inline keyboard.
     */
    public function sendMessage(string|int $chatId, string $text, ?array $inlineKeyboard = null, string $parseMode = 'HTML'): ?array
    {
        if (empty($this->botToken)) {
            Log::warning("TelegramService: TELEGRAM_BOT_TOKEN is not configured. Message not sent: {$text}");
            return null;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];

        if ($inlineKeyboard) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => $inlineKeyboard,
            ]);
        }

        try {
            $response = $this->httpClient()->post("{$this->apiUrl}/sendMessage", $payload);
            if (!$response->successful()) {
                Log::error("TelegramService sendMessage failed", ['response' => $response->json(), 'payload' => $payload]);
            }
            return $response->json();
        } catch (\Exception $e) {
            Log::error("TelegramService exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate Telegram Mini App initData cryptographic HMAC-SHA256 signature.
     */
    public function validateInitData(string $initData): ?array
    {
        if (empty($this->botToken) || empty($initData)) {
            return null;
        }

        parse_str($initData, $data);

        if (!isset($data['hash'])) {
            return null;
        }

        $hash = $data['hash'];
        unset($data['hash']);

        // Sort keys alphabetically
        ksort($data);

        $dataCheckString = [];
        foreach ($data as $key => $value) {
            $dataCheckString[] = "{$key}={$value}";
        }
        $dataCheckString = implode("\n", $dataCheckString);

        // Secret key is HMAC-SHA256 of bot token with key "WebAppData"
        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (hash_equals($calculatedHash, $hash)) {
            // Check auth_date not older than 24 hours (86400 seconds) to prevent replay attacks
            if (isset($data['auth_date']) && (time() - (int)$data['auth_date']) > 86400) {
                Log::warning("Telegram initData expired (auth_date older than 24h)");
                return null;
            }

            if (isset($data['user'])) {
                $data['user'] = json_decode($data['user'], true);
            }
            return $data;
        }

        return null;
    }

    /**
     * Format drink item options into clean readable text.
     */
    protected function formatDrinkOptions(?array $options): string
    {
        if (empty($options)) {
            return '';
        }

        $parts = [];
        foreach ($options as $key => $val) {
            $parts[] = ucfirst($key) . ': ' . $val;
        }

        return ' (' . implode(', ', $parts) . ')';
    }

    /**
     * Notification: Drink Order Created -> Notify Approver(s)
     */
    public function notifyApproverForDrinkOrder($order): void
    {
        $approvers = $order->branch->users()
            ->whereIn('role', ['hod', 'admin'])
            ->whereNotNull('telegram_chat_id')
            ->get();

        if ($approvers->isEmpty()) {
            $approvers = User::where('role', 'admin')
                ->whereNotNull('telegram_chat_id')
                ->get();
        }

        if ($approvers->isEmpty()) {
            return;
        }

        $text = "☕ <b>New Drink Request #{$order->id}</b>\n\n"
              . "<b>Requester:</b> {$order->user->name}\n"
              . "<b>Room:</b> {$order->room->name}\n"
              . "<b>Items:</b>\n";

        foreach ($order->items as $item) {
            $options = $this->formatDrinkOptions($item->selected_options);
            $text .= "• {$item->quantity}x {$item->drinkItem->name}{$options}\n";
        }

        if ($order->notes) {
            $text .= "\n<b>Notes:</b> {$order->notes}\n";
        }

        $keyboard = [
            [
                ['text' => '✅ Approve', 'callback_data' => "drink_approve_{$order->id}"],
                ['text' => '❌ Reject', 'callback_data' => "drink_reject_{$order->id}"],
            ]
        ];

        foreach ($approvers as $approver) {
            $this->sendMessage($approver->telegram_chat_id, $text, $keyboard);
        }
    }

    /**
     * Notification: New Drink Order -> Notify Baristas & Kitchen directly (no approval needed).
     */
    public function notifyBaristaForDrinkOrder($order): void
    {
        $order->loadMissing(['user', 'room', 'branch', 'items.drinkItem']);

        // Centralized kitchen: notify all linked baristas (HQ prepares both HQ and Branch orders)
        $baristas = User::where('role', 'barista')
            ->whereNotNull('telegram_chat_id')
            ->get();

        if ($baristas->isEmpty()) {
            return;
        }

        $typeBadge = match ($order->order_type) {
            'instant' => '⚡ QUICK DRINK',
            'extend' => "🔄 EXTEND / MORE (Ref #{$order->parent_order_id})",
            default => '📅 MEETING DRINK',
        };

        $when = $order->delivery_time ? $order->delivery_time->format('d/M/Y, h:i A') : 'ASAP';
        $text = "🔔 <b>{$typeBadge} — Order #{$order->id}</b>\n\n"
              . "<b>Branch:</b> {$order->branch->name}\n"
              . "<b>Room:</b> {$order->room->name}\n"
              . "<b>Requester:</b> {$order->user->name}\n"
              . "<b>Target Time:</b> {$when}\n";

        if ($order->extend_reason) {
            $text .= "<b>Reason:</b> " . ucfirst(str_replace('_', ' ', $order->extend_reason)) . "\n";
        }

        $text .= "\n<b>Items:</b>\n";
        foreach ($order->items as $item) {
            $opts = $this->formatDrinkOptions($item->selected_options);
            $text .= "• {$item->quantity}x <b>{$item->drinkItem->name}</b>{$opts}\n";
        }

        if ($order->notes) {
            $text .= "\n<b>Notes:</b> {$order->notes}\n";
        }

        $keyboard = [
            [
                ['text' => '👍 Start Making (Package)', 'callback_data' => "barista_accept_{$order->id}"],
            ]
        ];

        foreach ($baristas as $barista) {
            $this->sendMessage($barista->telegram_chat_id, $text, $keyboard);
        }
    }

    /**
     * Notification: Barista Finished Package -> Out for Delivery (User can confirm receipt).
     */
    public function notifyUserOrderInDelivery($order): void
    {
        $order->loadMissing(['user', 'room']);
        if (!$order->user || !$order->user->telegram_chat_id) {
            return;
        }

        $text = "🚚 <b>Your Drink Order #{$order->id} is OUT FOR DELIVERY!</b>\n\n"
              . "<b>Room:</b> {$order->room->name}\n"
              . "Please tap <b>Confirm Received</b> once it arrives (auto-completes in 15 mins):";

        $keyboard = [
            [
                ['text' => '✅ Confirm Received • បានទទួល', 'callback_data' => "user_received_{$order->id}"],
            ]
        ];

        $this->sendMessage($order->user->telegram_chat_id, $text, $keyboard);
    }
}

