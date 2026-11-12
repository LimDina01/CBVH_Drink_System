<?php

namespace App\Http\Controllers;

use App\Models\DrinkOrder;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(protected TelegramService $telegram)
    {
    }

    public function handle(Request $request): JsonResponse
    {
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

        if (!$chatId) return;

        $username = $message['from']['username'] ?? null;
        $user = $this->telegram->findLinkedTelegramUser((string) $chatId, $username);

        if (str_starts_with($text, '/start')) {
            $webAppUrl = config('services.telegram.webapp_url', env('TELEGRAM_WEBAPP_URL', 'http://localhost:8000'));

            if ($user) {
                $branchName = $user->branch?->name ?? 'HQ';
                $welcome = "👋 <b>Welcome back, {$user->name}!</b>\n\n"
                         . "<b>Branch:</b> {$branchName}\n"
                         . "<b>Role:</b> " . strtoupper($user->role) . "\n\n"
                         . "Tap below to open CBVH Drink System:";
                $btnLabel = '🚀 Open CBVH Drink System';
            } else {
                $welcome = "👋 <b>Welcome to CBVH Drink System!</b>\n\n"
                         . "Tap below and sign in <b>once</b> with your staff email &amp; password to link your Telegram account:";
                $btnLabel = '🔑 Open & Sign In';
            }

            $keyboard = [
                [
                    ['text' => $btnLabel, 'web_app' => ['url' => $webAppUrl]],
                ]
            ];

            $this->telegram->sendMessage($chatId, $welcome, $keyboard);
        }
    }

    protected function handleCallbackQuery(array $callback): void
    {
        $callbackId = $callback['id'] ?? null;
        $chatId = $callback['message']['chat']['id'] ?? null;
        $data = $callback['data'] ?? '';

        if (!$chatId) return;

        // 1. Drink Approval by HOD
        if (str_starts_with($data, 'drink_approve_')) {
            $orderId = str_replace('drink_approve_', '', $data);
            $order = DrinkOrder::find($orderId);
            if ($order && $order->status === 'pending_approval') {
                $order->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                ]);

                $this->telegram->sendMessage($chatId, "✅ <b>Drink Request #{$orderId} APPROVED</b>. The ticket has been forwarded to the Barista / Kitchen.");
                
                // Notify requester
                if ($order->user && $order->user->telegram_chat_id) {
                    $this->telegram->sendMessage($order->user->telegram_chat_id, "🎉 Good news! Your drink request #{$orderId} was <b>APPROVED</b> and sent to the Barista.");
                }

                // Notify barista
                $this->telegram->notifyBaristaForDrinkOrder($order);
            }
        } elseif (str_starts_with($data, 'drink_reject_')) {
            $orderId = str_replace('drink_reject_', '', $data);
            $order = DrinkOrder::find($orderId);
            if ($order && $order->status === 'pending_approval') {
                $order->update([
                    'status' => 'rejected_by_approver',
                    'approver_rejection_reason' => 'Rejected by manager/admin',
                ]);

                $this->telegram->sendMessage($chatId, "❌ <b>Drink Request #{$orderId} REJECTED</b>.");
                
                if ($order->user && $order->user->telegram_chat_id) {
                    $this->telegram->sendMessage($order->user->telegram_chat_id, "⚠️ Notice: Your drink request #{$orderId} was <b>rejected</b> by the approver.");
                }
            }
        }

        // 2. Barista Accept / Reject
        elseif (str_starts_with($data, 'barista_accept_')) {
            $orderId = str_replace('barista_accept_', '', $data);
            $order = DrinkOrder::find($orderId);
            if ($order) {
                $order->update([
                    'status' => 'preparing',
                    'barista_accepted_at' => now(),
                ]);

                $keyboard = [
                    [['text' => '☕ Mark Ready for Delivery', 'callback_data' => "barista_ready_{$orderId}"]]
                ];

                $this->telegram->sendMessage($chatId, "👨‍🍳 <b>Order #{$orderId} Accepted!</b> You are now preparing this order.", $keyboard);

                if ($order->user && $order->user->telegram_chat_id) {
                    $this->telegram->sendMessage($order->user->telegram_chat_id, "☕ The Barista has accepted your drink order #{$orderId} and is currently preparing it!");
                }
            }
        } elseif (str_starts_with($data, 'barista_ready_')) {
            $orderId = str_replace('barista_ready_', '', $data);
            $order = DrinkOrder::find($orderId);
            if ($order) {
                $order->update([
                    'status' => 'in_delivery',
                    'ready_at' => now(),
                    'in_delivery_at' => now(),
                ]);

                $this->telegram->sendMessage($chatId, "🚚 <b>Order #{$orderId} Out for Delivery!</b> Waiting for user confirmation.");
                $this->telegram->notifyUserOrderInDelivery($order);
            }
        } elseif (str_starts_with($data, 'user_received_')) {
            $orderId = str_replace('user_received_', '', $data);
            $order = DrinkOrder::find($orderId);
            if ($order && $order->status === 'in_delivery') {
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

