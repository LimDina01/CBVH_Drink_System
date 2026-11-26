<?php

namespace App\Http\Controllers;

use App\Models\DrinkOrder;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(protected TelegramService $telegram) {}

    public function handle(Request $request): JsonResponse
    {
        $expectedSecret = config('services.telegram.webhook_secret');
        if (! empty($expectedSecret)) {
            $headerSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
            if (! hash_equals((string) $expectedSecret, $headerSecret)) {
                Log::warning('Rejected Telegram webhook call with invalid secret token.');

                return response()->json(['ok' => false, 'error' => 'Unauthorized'], 403);
            }
        }

        $update = $request->all();
        Log::info('Telegram Webhook Update Received', $update);

        // Handle inline button callback queries
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);

            return response()->json(['ok' => true]);
        }

        // Handle normal text messages / commands
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);

            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => true]);
    }

    protected function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');

        if (! $chatId) {
            return;
        }

        $username = $message['from']['username'] ?? null;
        $user = $this->telegram->findLinkedTelegramUser((string) $chatId, $username);

        if (str_starts_with($text, '/start')) {
            $webAppUrl = config('services.telegram.webapp_url', env('TELEGRAM_WEBAPP_URL', 'http://localhost:8000'));

            if ($user) {
                $branchName = $user->branch?->name ?? 'HQ';
                $welcome = "👋 <b>Welcome back, {$user->name}!</b>\n\n"
                         ."<b>Branch:</b> {$branchName}\n"
                         .'<b>Role:</b> '.strtoupper($user->role)."\n\n"
                         .'Tap below to open CBVH Drink System:';
                $btnLabel = '🚀 Open CBVH Drink System';
            } else {
                $welcome = "👋 <b>Welcome to CBVH Drink System!</b>\n\n"
                         .'Tap below and sign in <b>once</b> with your Employee ID (or email) &amp; password to link your Telegram account:';
                $btnLabel = '🔑 Open & Sign In';
            }

            $keyboard = [
                [
                    ['text' => $btnLabel, 'web_app' => ['url' => $webAppUrl]],
                ],
            ];

            $this->telegram->sendMessage($chatId, $welcome, $keyboard);
        }
    }

    protected function handleCallbackQuery(array $callback): void
    {
        $chatId = $callback['from']['id'] ?? ($callback['message']['chat']['id'] ?? null);
        $data = $callback['data'] ?? '';

        if (! $chatId) {
            return;
        }

        $username = $callback['from']['username'] ?? null;
        $actor = $this->telegram->findLinkedTelegramUser((string) $chatId, $username);
        if (! $actor) {
            $this->telegram->sendMessage($chatId, '🔒 Your Telegram account is not linked. Please open the Mini App and sign in first.');

            return;
        }

        // Barista Accept / Ready
        if (str_starts_with($data, 'barista_accept_')) {
            if (! $actor->isBarista()) {
                $this->telegram->sendMessage($chatId, '⛔ Only Barista or Admin staff can accept kitchen orders.');

                return;
            }

            $orderId = str_replace('barista_accept_', '', $data);
            $order = DrinkOrder::find($orderId);
            if (! $order || $order->status !== 'approved') {
                $this->telegram->sendMessage($chatId, "ℹ️ Order #{$orderId} is already processed or no longer waiting in queue.");

                return;
            }

            if ($order->order_type === 'meeting' && $order->delivery_time && $order->delivery_time->isAfter(today()->endOfDay())) {
                $this->telegram->sendMessage($chatId, "🔒 Order #{$orderId} is for a future meeting day and cannot be started yet.");

                return;
            }

            $order->update([
                'status' => 'preparing',
                'barista_id' => $actor->id,
                'barista_accepted_at' => now(),
            ]);

            $readyLabel = $order->order_type === 'recurring'
                ? '🏁 Mark Delivered & Complete'
                : '☕ Mark Ready for Delivery';

            $keyboard = [
                [['text' => $readyLabel, 'callback_data' => "barista_ready_{$orderId}"]],
            ];

            $this->telegram->sendMessage($chatId, "👨‍🍳 <b>Order #{$orderId} Accepted!</b> You are now preparing this order.", $keyboard);

            if ($order->user && $order->user->telegram_chat_id) {
                $this->telegram->sendMessage($order->user->telegram_chat_id, "☕ The Barista has accepted your drink order #{$orderId} and is currently preparing it!");
            }
        } elseif (str_starts_with($data, 'barista_ready_')) {
            if (! $actor->isBarista()) {
                $this->telegram->sendMessage($chatId, '⛔ Only Barista or Admin staff can complete kitchen orders.');

                return;
            }

            $orderId = str_replace('barista_ready_', '', $data);
            $order = DrinkOrder::find($orderId);
            if (! $order || ! in_array($order->status, ['approved', 'preparing'], true)) {
                $this->telegram->sendMessage($chatId, "ℹ️ Order #{$orderId} is already in delivery or completed.");

                return;
            }

            if ($order->order_type === 'recurring') {
                $order->update([
                    'status' => 'completed',
                    'barista_id' => $order->barista_id ?? $actor->id,
                    'barista_accepted_at' => $order->barista_accepted_at ?? now(),
                    'ready_at' => now(),
                    'in_delivery_at' => now(),
                    'completed_at' => now(),
                ]);
                $this->telegram->sendMessage($chatId, "🏁 <b>Schedule Order #{$orderId} Delivered &amp; Completed!</b>");

                return;
            }

            $order->update([
                'status' => 'in_delivery',
                'barista_id' => $order->barista_id ?? $actor->id,
                'barista_accepted_at' => $order->barista_accepted_at ?? now(),
                'ready_at' => now(),
                'in_delivery_at' => now(),
            ]);

            $this->telegram->sendMessage($chatId, "🚚 <b>Order #{$orderId} Out for Delivery!</b> Waiting for user confirmation.");
            $this->telegram->notifyUserOrderInDelivery($order);
        } elseif (str_starts_with($data, 'user_received_')) {
            $orderId = str_replace('user_received_', '', $data);
            $order = DrinkOrder::find($orderId);
            if ($order && ($order->user_id === $actor->id || $actor->isAdmin()) && $order->status === 'in_delivery') {
                $order->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'auto_completed' => false,
                ]);

                $this->telegram->sendMessage($chatId, "✅ <b>Thank you!</b> Order #{$orderId} marked as received and completed.");
            }
        }
    }
}
