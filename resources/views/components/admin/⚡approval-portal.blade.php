<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Branch;
use App\Models\DrinkItem;
use App\Models\DrinkOrder;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

new class extends Component
{
    use WithPagination;

    public $activeSection = 'reports'; // 'reports', 'team', 'drinks'

    // --- Drink Management ---
    public string $drinkSearch = '';
    public string $drinkBranchFilter = 'all';
    public string $drinkCategoryFilter = 'all';
    public int $drinkPerPage = 12;
    public bool $showDrinkModal = false;
    public ?int $editingDrinkId = null;
    public string $drinkNameEn = '';
    public string $drinkNameKm = '';
    public string $drinkCategory = 'coffee';
    public array $drinkTargetBranches = [];
    public $drinkBranchId = null;
    public string $drinkDescriptionEn = '';
    public string $drinkDescriptionKm = '';
    public bool $drinkIsAvailable = true;
    public array $drinkLayers = [];

    // --- Report Filters ---
    public $filterBranchId = 'all';
    public $filterUserId = 'all';
    public $filterType = 'all'; // 'all', 'instant', 'meeting', 'extend'
    public $filterStatus = 'all';
    public $filterPeriod = 'this_month'; // 'today', 'this_month', 'last_month', 'all', 'custom'
    public $searchQuery = '';
    public int $perPage = 15;
    public $dateFrom;
    public $dateTo;

    // --- Create Staff Form ---
    public string $newName = '';
    public string $newEmployeeId = '';
    public string $newPassword = '';
    public string $newRole = 'employee';
    public $newBranchId = null;

    // --- Staff Directory Filters & Pagination ---
    public string $staffSearch = '';
    public int $staffPerPage = 10;

    // --- Password Reset Modal ---
    public ?int $resettingUserId = null;
    public string $resettingUserName = '';
    public string $resettingUserEmployeeId = '';
    public string $newStaffResetPassword = 'cbvh1234';
    public bool $showResetPasswordModal = false;

    // --- Settings ---
    public $enforceWorkingHours = true;
    public bool $showOptionsInTable = false;
    public $flashMessage = null;
    public $flashType = 'success';
    public int $lastKnownOrderId = 0;

    public function mount()
    {
        $this->setPeriod('this_month');
        $this->enforceWorkingHours = (bool) Cache::get('enforce_working_hours', true);
        $this->newBranchId = Branch::first()?->id;
        $this->lastKnownOrderId = (int) (DrinkOrder::max('id') ?? 0);
    }

    public function setPeriod(string $period): void
    {
        $this->filterPeriod = $period;
        if ($period === 'today') {
            $this->dateFrom = today()->toDateString();
            $this->dateTo = today()->toDateString();
        } elseif ($period === 'this_month') {
            $this->dateFrom = now()->startOfMonth()->toDateString();
            $this->dateTo = now()->endOfMonth()->toDateString();
        } elseif ($period === 'last_month') {
            $this->dateFrom = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
            $this->dateTo = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        } elseif ($period === 'all') {
            $this->dateFrom = null;
            $this->dateTo = null;
        }
        $this->resetPage();
    }

    public function updated($propertyName): void
    {
        if (in_array($propertyName, ['dateFrom', 'dateTo'], true)) {
            $this->filterPeriod = 'custom';
        }
        if (in_array($propertyName, ['filterBranchId', 'filterUserId', 'filterType', 'filterStatus', 'dateFrom', 'dateTo', 'searchQuery', 'filterPeriod', 'perPage'], true)) {
            $this->resetPage();
        }
        if (in_array($propertyName, ['staffSearch', 'staffPerPage'], true)) {
            $this->resetPage('staffPage');
        }
        if (in_array($propertyName, ['drinkSearch', 'drinkBranchFilter', 'drinkCategoryFilter', 'drinkPerPage'], true)) {
            $this->resetPage('drinkPage');
        }
    }

    public function createStaffAccount(): void
    {
        $this->validate([
            'newName' => 'required|min:2',
            'newEmployeeId' => 'required|min:2|unique:users,employee_id',
            'newPassword' => 'required|min:4',
            'newRole' => 'required|in:employee,barista,admin',
            'newBranchId' => 'required|exists:branches,id',
        ]);

        $cleanEmpId = strtoupper(trim($this->newEmployeeId));
        $generatedEmail = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $cleanEmpId)) . '@cbvh.internal';

        User::create([
            'name' => trim($this->newName),
            'employee_id' => $cleanEmpId,
            'email' => $generatedEmail,
            'password' => Hash::make($this->newPassword),
            'role' => $this->newRole,
            'branch_id' => $this->newBranchId,
        ]);

        $this->newName = '';
        $this->newEmployeeId = '';
        $this->newPassword = '';
        $this->flashMessage = "Staff account created for {$cleanEmpId}! They can now sign in with this Employee ID.";
        $this->flashType = 'success';
    }

    public function unlinkTelegram($userId): void
    {
        $user = User::find($userId);
        if ($user) {
            app(TelegramService::class)->unlinkUser($user);
            $this->flashMessage = "Unlinked Telegram from {$user->name}.";
            $this->flashType = 'success';
        }
    }

    public function openResetPasswordModal(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->resettingUserId = $user->id;
        $this->resettingUserName = $user->name;
        $this->resettingUserEmployeeId = $user->employee_id ?: 'No ID';
        $this->newStaffResetPassword = 'cbvh1234';
        $this->showResetPasswordModal = true;
    }

    public function closeResetPasswordModal(): void
    {
        $this->showResetPasswordModal = false;
        $this->resettingUserId = null;
    }

    public function saveNewPassword(): void
    {
        $this->validate([
            'newStaffResetPassword' => 'required|min:4',
        ]);

        if (!$this->resettingUserId) {
            return;
        }

        $user = User::findOrFail($this->resettingUserId);
        $user->update([
            'password' => Hash::make($this->newStaffResetPassword),
        ]);

        $this->flashMessage = __('cbvh.password_reset_success', ['name' => $user->name]);
        $this->flashType = 'success';
        $this->showResetPasswordModal = false;
        $this->resettingUserId = null;
    }

    public function toggleWorkingHoursRule()
    {
        $this->enforceWorkingHours = !$this->enforceWorkingHours;
        Cache::forever('enforce_working_hours', $this->enforceWorkingHours);

        $this->flashMessage = $this->enforceWorkingHours
            ? __('cbvh.enforced_hours')
            : __('cbvh.testing_24_7');
        $this->flashType = 'success';
    }

    public function markOrderCompleted($orderId)
    {
        $order = DrinkOrder::find($orderId);
        if ($order) {
            $order->update([
                'status' => 'completed',
                'completed_at' => now(),
                'auto_completed' => false,
            ]);
            $this->flashMessage = "#{$orderId} — " . __('cbvh.status_completed');
            $this->flashType = 'success';
        }
    }

    public function sendLegacyToBarista($orderId)
    {
        $order = DrinkOrder::find($orderId);
        if ($order && $order->status === 'pending_approval') {
            $order->update([
                'status' => 'approved',
                'approved_at' => now(),
            ]);
            app(TelegramService::class)->notifyBaristaForDrinkOrder($order);
            $this->flashMessage = "#{$orderId} forwarded to Barista.";
            $this->flashType = 'success';
        }
    }

    public function updateUserRole($userId, $role)
    {
        $user = User::find($userId);
        if ($user && in_array($role, ['employee', 'barista', 'admin'])) {
            $user->update(['role' => $role]);
            $this->flashMessage = "Updated {$user->name} (" . strtoupper($role) . ").";
            $this->flashType = 'success';
        }
    }

    public function updateUserBranch($userId, $branchId)
    {
        $user = User::find($userId);
        if ($user && Branch::where('id', $branchId)->exists()) {
            $user->update(['branch_id' => $branchId]);
            $this->flashMessage = "Updated {$user->name}'s branch.";
            $this->flashType = 'success';
        }
    }

    public function sendTestTelegram($userId)
    {
        $user = User::find($userId);
        if (!$user || !$user->telegram_chat_id) {
            $this->flashMessage = 'User has not linked Telegram yet.';
            $this->flashType = 'error';
            return;
        }

        $res = app(TelegramService::class)->sendMessage(
            $user->telegram_chat_id,
            "✅ <b>CBVH Drink System Test Alert:</b> Telegram notifications are working! (Role: " . strtoupper($user->role) . ")"
        );

        if ($res && ($res['ok'] ?? false)) {
            $this->flashMessage = "Test message sent to {$user->name} on Telegram!";
            $this->flashType = 'success';
        } else {
            $this->flashMessage = "Failed to send Telegram message.";
            $this->flashType = 'error';
        }
    }

    public function registerWebhook()
    {
        $webhookUrl = rtrim(request()->getSchemeAndHttpHost(), '/') . '/telegram/webhook';
        $res = app(TelegramService::class)->setWebhook($webhookUrl);

        if ($res && ($res['ok'] ?? false)) {
            $this->flashMessage = "Telegram Bot Webhook connected to: {$webhookUrl}";
            $this->flashType = 'success';
        } else {
            $desc = $res['description'] ?? 'Open this page via your HTTPS Cloudflare URL first.';
            $this->flashMessage = "Webhook failed: {$desc}";
            $this->flashType = 'error';
        }
    }

    public function openCreateDrinkModal(): void
    {
        $this->editingDrinkId = null;
        $this->drinkNameEn = '';
        $this->drinkNameKm = '';
        $this->drinkCategory = 'coffee';
        $this->drinkTargetBranches = Branch::pluck('id')->map(fn($id) => (int) $id)->all();
        $this->drinkDescriptionEn = '';
        $this->drinkDescriptionKm = '';
        $this->drinkIsAvailable = true;
        $this->drinkLayers = [
            [
                'id' => 'sweetness',
                'name_en' => 'Sweetness',
                'name_km' => 'កម្រិតជាតិផ្អែម',
                'options' => [
                    ['en' => '0% No Sugar', 'km' => 'គ្មានស្ករ ០%'],
                    ['en' => '50% Less Sugar', 'km' => 'ស្ករតិច ៥០%'],
                    ['en' => '100% Normal', 'km' => 'ស្ករធម្មតា ១០០%'],
                ],
            ],
            [
                'id' => 'ice',
                'name_en' => 'Ice Level',
                'name_km' => 'កម្រិតទឹកកក',
                'options' => [
                    ['en' => 'No Ice', 'km' => 'គ្មានទឹកកក'],
                    ['en' => 'Less Ice', 'km' => 'ទឹកកកតិច'],
                    ['en' => 'Normal Ice', 'km' => 'ទឹកកកធម្មតា'],
                ],
            ],
        ];
        $this->showDrinkModal = true;
    }

    public function openEditDrinkModal(int $drinkId): void
    {
        $drink = DrinkItem::findOrFail($drinkId);
        $this->editingDrinkId = $drink->id;
        $this->drinkNameEn = $drink->name;
        $this->drinkNameKm = $drink->name_km ?? '';
        $this->drinkCategory = $drink->category ?? 'coffee';
        $matching = DrinkItem::where('name', $drink->name)->pluck('branch_id')->map(fn($id) => (int) $id)->all();
        $this->drinkTargetBranches = !empty($matching) ? $matching : [(int) $drink->branch_id];
        $this->drinkDescriptionEn = $drink->description ?? '';
        $this->drinkDescriptionKm = $drink->description_km ?? '';
        $this->drinkIsAvailable = (bool) $drink->is_available;
        $this->drinkLayers = $drink->normalized_layers;
        $this->showDrinkModal = true;
    }

    public function closeDrinkModal(): void
    {
        $this->showDrinkModal = false;
        $this->editingDrinkId = null;
    }

    public function addLayer(): void
    {
        $this->drinkLayers[] = [
            'id' => 'layer_' . uniqid(),
            'name_en' => 'New Layer',
            'name_km' => '',
            'options' => [
                ['en' => 'Option 1', 'km' => ''],
                ['en' => 'Option 2', 'km' => ''],
            ],
        ];
    }

    public function removeLayer(int $layerIndex): void
    {
        unset($this->drinkLayers[$layerIndex]);
        $this->drinkLayers = array_values($this->drinkLayers);
    }

    public function addChoice(int $layerIndex): void
    {
        if (isset($this->drinkLayers[$layerIndex])) {
            $this->drinkLayers[$layerIndex]['options'][] = [
                'en' => '',
                'km' => '',
            ];
        }
    }

    public function removeChoice(int $layerIndex, int $choiceIndex): void
    {
        if (isset($this->drinkLayers[$layerIndex]['options'][$choiceIndex])) {
            unset($this->drinkLayers[$layerIndex]['options'][$choiceIndex]);
            $this->drinkLayers[$layerIndex]['options'] = array_values($this->drinkLayers[$layerIndex]['options']);
        }
    }

    public function addSugarPreset(): void
    {
        $this->drinkLayers[] = [
            'id' => 'sweetness_' . uniqid(),
            'name_en' => 'Sweetness',
            'name_km' => 'កម្រិតជាតិផ្អែម',
            'options' => [
                ['en' => '0% No Sugar', 'km' => 'គ្មានស្ករ ០%'],
                ['en' => '25% Sweet', 'km' => 'ផ្អែមតិច ២៥%'],
                ['en' => '50% Half Sweet', 'km' => 'ផ្អែមល្មម ៥០%'],
                ['en' => '100% Normal', 'km' => 'ស្ករធម្មតា ១០០%'],
            ],
        ];
    }

    public function addIcePreset(): void
    {
        $this->drinkLayers[] = [
            'id' => 'ice_' . uniqid(),
            'name_en' => 'Ice Level',
            'name_km' => 'កម្រិតទឹកកក',
            'options' => [
                ['en' => 'Hot', 'km' => 'ក្តៅ'],
                ['en' => 'No Ice', 'km' => 'គ្មានទឹកកក'],
                ['en' => 'Less Ice', 'km' => 'ទឹកកកតិច'],
                ['en' => 'Normal Ice', 'km' => 'ទឹកកកធម្មតា'],
            ],
        ];
    }

    public function addStrengthPreset(): void
    {
        $this->drinkLayers[] = [
            'id' => 'strength_' . uniqid(),
            'name_en' => 'Coffee Strength',
            'name_km' => 'កម្រិតកាហ្វេ',
            'options' => [
                ['en' => 'Single Shot', 'km' => 'កាហ្វេ ១ កូនកែវ'],
                ['en' => 'Double Shot', 'km' => 'កាហ្វេ ២ កូនកែវ'],
                ['en' => 'Decaf', 'km' => 'គ្មានជាតិកាហ្វេអ៊ីន'],
            ],
        ];
    }

    public function addShakePreset(): void
    {
        $this->drinkLayers[] = [
            'id' => 'preparation_' . uniqid(),
            'name_en' => 'Preparation Style',
            'name_km' => 'របៀបក្រឡុក / ឆុង',
            'options' => [
                ['en' => 'Shaken', 'km' => 'ក្រឡុក (Shake)'],
                ['en' => 'Stirred', 'km' => 'កូរធម្មតា (Stirred)'],
                ['en' => 'Blended / Frappe', 'km' => 'កិនម៉ត់ (Blended)'],
            ],
        ];
    }

    public function toggleDrinkAvailability(int $drinkId): void
    {
        $drink = DrinkItem::findOrFail($drinkId);
        $drink->update(['is_available' => !$drink->is_available]);
        $statusText = $drink->is_available ? __('cbvh.in_stock') : __('cbvh.out_of_stock');
        $this->flashMessage = "{$drink->name}: {$statusText}";
        $this->flashType = 'success';
    }

    public function deleteDrink(int $drinkId): void
    {
        $drink = DrinkItem::findOrFail($drinkId);
        $name = $drink->name;
        $drink->delete();
        $this->flashMessage = __('cbvh.drink_deleted_success') . " ({$name})";
        $this->flashType = 'success';
    }

    public function deleteDrinkGroup(string $name): void
    {
        DrinkItem::where('name', $name)->delete();
        $this->flashMessage = __('cbvh.drink_deleted_success') . " ({$name}) across all branches";
        $this->flashType = 'success';
    }

    public function saveDrink(): void
    {
        $this->validate([
            'drinkNameEn' => 'required|min:2|max:100',
            'drinkCategory' => 'required|string|max:50',
            'drinkTargetBranches' => 'required|array|min:1',
        ]);

        $cleanedLayers = [];
        foreach ($this->drinkLayers as $layer) {
            $nameEn = trim($layer['name_en'] ?? '');
            if (empty($nameEn)) continue;

            $validOptions = [];
            foreach ($layer['options'] ?? [] as $opt) {
                $optEn = trim(is_array($opt) ? ($opt['en'] ?? '') : (string) $opt);
                $optKm = trim(is_array($opt) ? ($opt['km'] ?? '') : '');
                if ($optEn !== '') {
                    $validOptions[] = [
                        'en' => $optEn,
                        'km' => $optKm ?: $optEn,
                    ];
                }
            }

            if (!empty($validOptions)) {
                $cleanedLayers[] = [
                    'id' => $layer['id'] ?? ('layer_' . uniqid()),
                    'name_en' => $nameEn,
                    'name_km' => trim($layer['name_km'] ?? '') ?: $nameEn,
                    'options' => $validOptions,
                ];
            }
        }

        $name = trim($this->drinkNameEn);
        $nameKm = trim($this->drinkNameKm) ?: null;
        $category = strtolower(trim($this->drinkCategory));
        $desc = trim($this->drinkDescriptionEn) ?: null;
        $descKm = trim($this->drinkDescriptionKm) ?: null;

        if ($this->editingDrinkId) {
            $drink = DrinkItem::findOrFail($this->editingDrinkId);
            $oldName = $drink->name;
            $targetBranchIds = array_map('intval', (array) $this->drinkTargetBranches);

            foreach ($targetBranchIds as $branchId) {
                $existing = DrinkItem::where('branch_id', $branchId)
                    ->where(function ($q) use ($drink, $oldName) {
                        $q->where('id', $drink->id)->orWhere('name', $oldName);
                    })->first();

                if ($existing) {
                    $existing->update([
                        'name' => $name,
                        'name_km' => $nameKm,
                        'category' => $category,
                        'description' => $desc,
                        'description_km' => $descKm,
                        'is_available' => (bool) $this->drinkIsAvailable,
                        'options_config' => ['layers' => $cleanedLayers],
                    ]);
                } else {
                    DrinkItem::create([
                        'branch_id' => $branchId,
                        'name' => $name,
                        'name_km' => $nameKm,
                        'category' => $category,
                        'description' => $desc,
                        'description_km' => $descKm,
                        'is_available' => (bool) $this->drinkIsAvailable,
                        'options_config' => ['layers' => $cleanedLayers],
                    ]);
                }
            }

            // Remove from branches that were unchecked/unselected
            DrinkItem::where(function ($q) use ($drink, $oldName) {
                $q->where('id', $drink->id)->orWhere('name', $oldName);
            })->whereNotIn('branch_id', $targetBranchIds)->delete();

            $this->flashMessage = __('cbvh.drink_updated_success') . " ({$name})";
        } else {
            $targetBranchIds = array_map('intval', (array) $this->drinkTargetBranches);
            foreach ($targetBranchIds as $branchId) {
                DrinkItem::updateOrCreate(
                    ['branch_id' => $branchId, 'name' => $name],
                    [
                        'name_km' => $nameKm,
                        'category' => $category,
                        'description' => $desc,
                        'description_km' => $descKm,
                        'is_available' => (bool) $this->drinkIsAvailable,
                        'options_config' => ['layers' => $cleanedLayers],
                    ]
                );
            }
            $this->flashMessage = __('cbvh.drink_created_success') . " ({$name})";
        }

        $this->flashType = 'success';
        $this->showDrinkModal = false;
        $this->editingDrinkId = null;
    }

    protected function buildFilteredOrdersQuery()
    {
        $query = DrinkOrder::with(['user', 'branch', 'room', 'items.drinkItem'])->latest();

        if ($this->filterBranchId !== 'all') {
            $query->where('branch_id', $this->filterBranchId);
        }

        if ($this->filterUserId !== 'all') {
            $query->where('user_id', $this->filterUserId);
        }

        if ($this->filterType !== 'all') {
            $query->where('order_type', $this->filterType);
        }

        if ($this->filterStatus !== 'all') {
            $query->where('status', $this->filterStatus);
        }

        if ($this->dateFrom) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        if (!empty(trim($this->searchQuery))) {
            $s = trim($this->searchQuery);
            $query->where(function ($q) use ($s) {
                $q->where('id', 'like', "%{$s}%")
                    ->orWhere('notes', 'like', "%{$s}%")
                    ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('room', fn($rq) => $rq->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('items.drinkItem', fn($dq) => $dq->where('name', 'like', "%{$s}%"));
            });
        }

        return $query;
    }

    public function exportCsv()
    {
        $orders = $this->buildFilteredOrdersQuery()->get();
        $filename = 'cbvh-drinks-report-' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($orders) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Order ID',
                'Order Type',
                'Parent Order ID',
                'Extend Reason',
                'Status',
                'Branch',
                'Room / Location',
                'Requester',
                'People Count',
                'Total Cups',
                'Drinks Summary',
                'Notes',
                'Reference Attachment',
                'Target / Meeting Time',
                'Ordered At',
                'In Delivery At',
                'Completed At',
                'Auto Completed (10m)',
            ]);

            foreach ($orders as $order) {
                // Group drinks by clean base name (e.g. Hot Latte + Iced Latte => Latte) without sugar/ice clutter
                $drinksList = $order->items
                    ->groupBy(fn($it) => \App\Support\CbvhLocale::toBaseDrinkName($it->drinkItem?->name))
                    ->map(function ($group, $baseName) {
                        $qty = $group->sum('quantity');
                        return "{$qty}x {$baseName}";
                    })
                    ->implode(' | ');

                fputcsv($handle, [
                    $order->id,
                    strtoupper($order->order_type),
                    $order->parent_order_id ?? '',
                    $order->extend_reason ?? '',
                    strtoupper($order->status),
                    $order->branch?->name ?? '',
                    $order->room?->name ?? '',
                    $order->user?->name ?? '',
                    $order->people_count,
                    $order->items->sum('quantity'),
                    $drinksList,
                    $order->notes ?? '',
                    $order->attachment_name ?? '',
                    $order->delivery_time?->format('Y-m-d H:i') ?? '',
                    $order->created_at?->format('Y-m-d H:i') ?? '',
                    $order->in_delivery_at?->format('Y-m-d H:i') ?? '',
                    $order->completed_at?->format('Y-m-d H:i') ?? '',
                    $order->auto_completed ? 'YES' : 'NO',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportDrinkTotalsCsv()
    {
        $orders = $this->buildFilteredOrdersQuery()->with('items.drinkItem')->get();
        $filename = 'cbvh-drink-summary-' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($orders) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Drink Item',
                'Category',
                'Total Cups Ordered',
            ]);

            $summary = [];
            foreach ($orders as $order) {
                foreach ($order->items as $it) {
                    $base = \App\Support\CbvhLocale::toBaseDrinkName($it->drinkItem?->name ?? 'Other');
                    $category = $it->drinkItem?->category ?? 'drink';
                    if (!isset($summary[$base])) {
                        $summary[$base] = [
                            'name' => $base,
                            'category' => $category,
                            'cups' => 0,
                        ];
                    }
                    $summary[$base]['cups'] += (int) $it->quantity;
                }
            }

            uasort($summary, fn($a, $b) => $b['cups'] <=> $a['cups']);

            foreach ($summary as $row) {
                fputcsv($handle, [
                    $row['name'],
                    ucfirst($row['category']),
                    $row['cups'],
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['TOTAL ALL DRINKS', '', array_sum(array_column($summary, 'cups'))]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function with()
    {
        DrinkOrder::autoCompleteExpiredDeliveries();

        $latestApproved = DrinkOrder::where('status', 'approved')->orderByDesc('id')->first();
        if ($latestApproved && $latestApproved->id > $this->lastKnownOrderId) {
            $this->lastKnownOrderId = (int) $latestApproved->id;
            $orderType = ($latestApproved->order_type === 'meeting') ? 'meeting' : 'quick';
            $this->dispatch('new-admin-order', orderType: $orderType);
        }

        $allFilteredOrders = $this->buildFilteredOrdersQuery()->get();
        $paginatedOrders = $this->buildFilteredOrdersQuery()->paginate($this->perPage);
        $totalCups = $allFilteredOrders->sum(fn($o) => $o->items->sum('quantity'));

        $drinkTotals = [];
        foreach ($allFilteredOrders as $order) {
            foreach ($order->items as $it) {
                $base = \App\Support\CbvhLocale::toBaseDrinkName($it->drinkItem?->name ?? 'Other');
                $category = $it->drinkItem?->category ?? 'drink';
                if (!isset($drinkTotals[$base])) {
                    $drinkTotals[$base] = [
                        'name' => $base,
                        'category' => $category,
                        'cups' => 0,
                    ];
                }
                $drinkTotals[$base]['cups'] += (int) $it->quantity;
            }
        }
        uasort($drinkTotals, fn($a, $b) => $b['cups'] <=> $a['cups']);

        $staffQuery = User::with('branch')
            ->orderByRaw('CASE WHEN telegram_chat_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('name');

        if (!empty($this->staffSearch)) {
            $s = trim($this->staffSearch);
            $staffQuery->where(function ($sq) use ($s) {
                $sq->where('name', 'like', "%{$s}%")
                    ->orWhere('employee_id', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('telegram_username', 'like', "%{$s}%");
            });
        }

        $paginatedStaff = $staffQuery->paginate($this->staffPerPage, pageName: 'staffPage');

        $drinkQuery = DrinkItem::with('branch')->orderBy('name');
        if ($this->drinkBranchFilter !== 'all') {
            $drinkQuery->where('branch_id', $this->drinkBranchFilter);
        }
        if ($this->drinkCategoryFilter !== 'all') {
            $drinkQuery->where('category', $this->drinkCategoryFilter);
        }
        if (!empty(trim($this->drinkSearch))) {
            $s = trim($this->drinkSearch);
            $drinkQuery->where(function ($dq) use ($s) {
                $dq->where('name', 'like', "%{$s}%")
                    ->orWhere('name_km', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%")
                    ->orWhere('category', 'like', "%{$s}%");
            });
        }

        if ($this->drinkBranchFilter === 'all') {
            $allDrinks = $drinkQuery->get();
            $groupedCollection = $allDrinks->groupBy(fn($d) => strtolower(trim($d->name)))
                ->map(function ($group) {
                    $primary = $group->first();
                    return (object) [
                        'id' => $primary->id,
                        'name' => $primary->name,
                        'name_km' => $primary->name_km,
                        'category' => $primary->category,
                        'description' => $primary->description,
                        'description_km' => $primary->description_km,
                        'normalized_layers' => $primary->normalized_layers,
                        'branch' => null,
                        'is_available' => $group->some(fn($i) => (bool) $i->is_available),
                        'branch_items' => $group->map(fn($item) => (object) [
                            'id' => $item->id,
                            'branch_id' => $item->branch_id,
                            'branch_code' => $item->branch?->code ?? "B{$item->branch_id}",
                            'branch_name' => $item->branch?->name ?? "Branch {$item->branch_id}",
                            'is_hq' => (bool) $item->branch?->is_hq,
                            'is_available' => (bool) $item->is_available,
                        ])->values()->all(),
                    ];
                })->values();

            $currentPage = LengthAwarePaginator::resolveCurrentPage('drinkPage');
            $slice = $groupedCollection->slice(($currentPage - 1) * $this->drinkPerPage, $this->drinkPerPage)->values();
            $paginatedDrinks = new LengthAwarePaginator(
                $slice,
                $groupedCollection->count(),
                $this->drinkPerPage,
                $currentPage,
                ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'drinkPage']
            );
        } else {
            $paginatedDrinks = $drinkQuery->paginate($this->drinkPerPage, pageName: 'drinkPage');
        }
        $drinkCategories = DrinkItem::select('category')->distinct()->pluck('category')->filter()->values()->all();
        if (empty($drinkCategories)) {
            $drinkCategories = ['coffee', 'tea', 'special'];
        }

        return [
            'branches' => Branch::all(),
            'orders' => $paginatedOrders,
            'totalOrders' => $allFilteredOrders->count(),
            'totalCups' => $totalCups,
            'quickCount' => $allFilteredOrders->where('order_type', 'instant')->count(),
            'meetingCount' => $allFilteredOrders->where('order_type', 'meeting')->count(),
            'extendCount' => $allFilteredOrders->where('order_type', 'extend')->count(),
            'drinkTotals' => $drinkTotals,
            'filterUsers' => User::orderBy('name')->get(),
            'users' => $paginatedStaff,
            'menuDrinks' => $paginatedDrinks,
            'drinkCategories' => $drinkCategories,
            'currentHostUrl' => request()->getSchemeAndHttpHost(),
        ];
    }
};
?>

<div wire:poll.8s
     x-data="{
        confirmLogout: false,
        soundOn: false,
        audioCtx: null,
        unlockAudio() {
            ['admin-sound-quick', 'admin-sound-meeting'].forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.load();
                }
            });
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
        playOrderAlert(type = 'quick') {
            if (!this.soundOn) return;
            this.unlockAudio();

            try {
                const audioId = (type === 'meeting') ? 'admin-sound-meeting' : 'admin-sound-quick';
                const el = document.getElementById(audioId);
                if (el) {
                    el.currentTime = 0;
                    const p = el.play();
                    if (p !== undefined) {
                        p.catch(err => {
                            console.warn('Admin audio play failed, falling back to chime:', err);
                            this.playDingDong();
                        });
                    }
                } else {
                    this.playDingDong();
                }
            } catch (e) {
                this.playDingDong();
            }
        }
     }"
     @click.window="unlockAudio()"
     @touchstart.window="unlockAudio()"
     @new-admin-order.window="playOrderAlert($event.detail?.orderType || 'quick')"
     class="min-h-screen bg-[#FAF6F3]/80 dark:bg-[#1A1412]/90 text-[#2C211C] dark:text-[#F5EDE7] flex flex-col">

    <!-- Preloaded Sound Elements (Chime + Official Voice merged) -->
    <audio id="admin-sound-quick" preload="auto" src="{{ asset('sounds/voice-quick.wav') }}"></audio>
    <audio id="admin-sound-meeting" preload="auto" src="{{ asset('sounds/voice-meeting.wav') }}"></audio>
    <!-- Header -->
    <header class="bg-white/95 dark:bg-[#241C19]/95 backdrop-blur-md border-b border-[#E8DDD6] dark:border-[#3D2F29] px-6 py-3.5 flex flex-wrap items-center justify-between gap-4 sticky top-0 z-10 shadow-[0_2px_16px_rgba(158,107,85,0.06)]">
        <div class="flex items-center space-x-3.5">
            <div class="bg-white rounded-xl px-2.5 py-1 border border-[#E8DDD6] shadow-2xs">
                <img src="{{ asset('images/cbvh-logo.png') }}" alt="CBVH" class="h-8 w-auto object-contain" />
            </div>
            <div>
                <h1 class="text-base font-bold leading-tight text-[#2C211C] dark:text-[#F5EDE7]">{{ __('cbvh.admin_title') }}</h1>
                <p class="text-xs text-[#7C6A60] dark:text-[#BBA79D]">{{ __('cbvh.admin_subtitle') }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Sound Alert Toggle for Admin -->
            <button type="button" @click="soundOn = !soundOn; if(soundOn) playOrderAlert('quick')"
                    class="px-3 py-2 rounded-xl text-xs font-bold border transition cursor-pointer flex items-center space-x-1"
                    :class="soundOn ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-[#FAF6F3] border-[#E8DDD6] text-[#7C6A60]'"
                    title="{{ __('cbvh.sound_on') }}">
                <span x-show="soundOn">🔊 {{ __('cbvh.sound_on') }}</span>
                <span x-show="!soundOn" x-cloak>🔇 {{ __('cbvh.muted') }}</span>
            </button>
            <button wire:click="$set('activeSection', 'reports')"
                    class="px-3.5 py-2 rounded-xl text-xs font-bold transition cursor-pointer {{ $activeSection === 'reports' ? 'bg-[#9E6B55] text-white' : 'bg-[#F5EDE7] text-[#835440] border border-[#E8DDD6]' }}">
                📊 {{ __('cbvh.tab_tracking_reports') }}
            </button>
            <button wire:click="$set('activeSection', 'team')"
                    class="px-3.5 py-2 rounded-xl text-xs font-bold transition cursor-pointer {{ $activeSection === 'team' ? 'bg-[#9E6B55] text-white' : 'bg-[#F5EDE7] text-[#835440] border border-[#E8DDD6]' }}">
                👥 {{ __('cbvh.tab_team_bot') }}
            </button>
            <button wire:click="$set('activeSection', 'drinks')"
                    class="px-3.5 py-2 rounded-xl text-xs font-bold transition cursor-pointer {{ $activeSection === 'drinks' ? 'bg-[#9E6B55] text-white' : 'bg-[#F5EDE7] text-[#835440] border border-[#E8DDD6]' }}">
                ☕ {{ __('cbvh.tab_drink_menu') }}
            </button>

            <a href="{{ route('lang.switch', app()->getLocale() === 'km' ? 'en' : 'km') }}"
               class="px-3 py-2 rounded-xl text-xs font-bold bg-[#FAF6F3] border border-[#E8DDD6] text-[#835440]">
                {{ app()->getLocale() === 'km' ? '🇬🇧 EN' : '🇰🇭 ខ្មែរ' }}
            </a>

            <a href="/barista" class="px-3.5 py-2 rounded-xl bg-white border border-[#E8DDD6] text-[#835440] text-xs font-bold hover:bg-[#F5EDE7]">{{ __('cbvh.barista') }}</a>
            <a href="/" class="px-3.5 py-2 rounded-xl bg-[#2C211C] text-white text-xs font-bold">{{ __('cbvh.mini_app') }} &rarr;</a>

            <button type="button" @click="confirmLogout = true" class="px-3 py-2 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold cursor-pointer">
                {{ __('cbvh.logout') }}
            </button>
        </div>
    </header>

    <!-- Floating Toast Notification (Modern, Non-Intrusive & Auto-Dismissing) -->
    @if($flashMessage)
        <div wire:key="admin-toast-{{ md5($flashMessage . ($flashType ?? 'success') . microtime()) }}"
             x-data="{ show: true }"
             x-init="setTimeout(() => { show = false; setTimeout(() => $wire.set('flashMessage', null), 300); }, 3200)"
             x-show="show"
             x-transition:enter="transition ease-out duration-300 transform"
             x-transition:enter-start="opacity-0 translate-y-4 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-200 transform"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 translate-y-2 scale-95"
             class="fixed bottom-6 right-6 z-50 max-w-md pointer-events-auto">
            <div class="flex items-center space-x-3 px-4 py-3.5 rounded-2xl shadow-2xl backdrop-blur-md border {{ ($flashType ?? 'success') === 'success' ? 'bg-white/95 dark:bg-[#241C19]/95 border-emerald-400 dark:border-emerald-600/70 text-[#2C211C] dark:text-[#F5EDE7]' : 'bg-rose-50/95 dark:bg-rose-950/90 border-rose-400 dark:border-rose-700 text-rose-900 dark:text-rose-100' }}">
                <span class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 text-xs font-black {{ ($flashType ?? 'success') === 'success' ? 'bg-emerald-500 text-white shadow-xs' : 'bg-rose-500 text-white shadow-xs' }}">
                    {{ ($flashType ?? 'success') === 'success' ? '✓' : '✕' }}
                </span>
                <span class="text-xs sm:text-sm font-bold leading-snug flex-1">{{ $flashMessage }}</span>
                <button type="button" @click="show = false; setTimeout(() => $wire.set('flashMessage', null), 300)"
                        class="p-1 text-[#7C6A60] hover:text-[#2C211C] dark:hover:text-white rounded-lg transition cursor-pointer text-base leading-none">
                    &times;
                </button>
            </div>
        </div>
    @endif

    <main class="max-w-7xl mx-auto w-full px-6 py-6 flex-1 space-y-6">

        @if($activeSection === 'reports')
            <!-- Quick Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#E8DDD6] shadow-2xs">
                    <div class="text-[11px] font-bold text-[#7C6A60] uppercase">{{ __('cbvh.total_orders') }}</div>
                    <div class="text-2xl font-black text-[#2C211C] dark:text-[#F5EDE7] mt-1">{{ $totalOrders }}</div>
                </div>
                <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#E8DDD6] shadow-2xs">
                    <div class="text-[11px] font-bold text-[#7C6A60] uppercase">{{ __('cbvh.total_cups') }}</div>
                    <div class="text-2xl font-black text-[#9E6B55] mt-1">{{ $totalCups }}</div>
                </div>
                <div class="bg-amber-50 p-4 rounded-2xl border border-amber-200 shadow-2xs">
                    <div class="text-[11px] font-bold text-amber-800 uppercase">⚡ {{ __('cbvh.quick_drink') }}</div>
                    <div class="text-2xl font-black text-amber-900 mt-1">{{ $quickCount }}</div>
                </div>
                <div class="bg-[#F5EDE7] p-4 rounded-2xl border border-[#E8DDD6] shadow-2xs">
                    <div class="text-[11px] font-bold text-[#835440] uppercase">📅 {{ __('cbvh.meeting_drink') }}</div>
                    <div class="text-2xl font-black text-[#2C211C] mt-1">{{ $meetingCount }}</div>
                </div>
                <div class="bg-purple-50 p-4 rounded-2xl border border-purple-200 shadow-2xs">
                    <div class="text-[11px] font-bold text-purple-800 uppercase">🔄 {{ __('cbvh.reason_spilled') }} / +</div>
                    <div class="text-2xl font-black text-purple-900 mt-1">{{ $extendCount }}</div>
                </div>
            </div>

            <!-- Filter Bar + Export Actions -->
            <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xs space-y-3.5">
                <!-- Row 1: Time Presets + Export Action Buttons -->
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <!-- Period Presets -->
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button type="button" wire:click="setPeriod('today')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer {{ $filterPeriod === 'today' ? 'bg-[#9E6B55] text-white shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#2A201C] text-[#7C6A60] dark:text-[#BBA79D] border border-[#E8DDD6] dark:border-[#3D2F29] hover:bg-[#F5EDE7]' }}">
                            📅 {{ __('cbvh.preset_today') }}
                        </button>
                        <button type="button" wire:click="setPeriod('this_month')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer {{ $filterPeriod === 'this_month' ? 'bg-[#9E6B55] text-white shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#2A201C] text-[#7C6A60] dark:text-[#BBA79D] border border-[#E8DDD6] dark:border-[#3D2F29] hover:bg-[#F5EDE7]' }}">
                            🗓️ {{ __('cbvh.preset_this_month') }} ({{ now()->format('M Y') }})
                        </button>
                        <button type="button" wire:click="setPeriod('last_month')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer {{ $filterPeriod === 'last_month' ? 'bg-[#9E6B55] text-white shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#2A201C] text-[#7C6A60] dark:text-[#BBA79D] border border-[#E8DDD6] dark:border-[#3D2F29] hover:bg-[#F5EDE7]' }}">
                            ⏪ {{ __('cbvh.preset_last_month') }} ({{ now()->subMonthNoOverflow()->format('M Y') }})
                        </button>
                        <button type="button" wire:click="setPeriod('all')"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer {{ $filterPeriod === 'all' ? 'bg-[#9E6B55] text-white shadow-xs' : 'bg-[#FAF6F3] dark:bg-[#2A201C] text-[#7C6A60] dark:text-[#BBA79D] border border-[#E8DDD6] dark:border-[#3D2F29] hover:bg-[#F5EDE7]' }}">
                            🌐 {{ __('cbvh.preset_all_time') }}
                        </button>
                    </div>

                    <!-- Export Buttons -->
                    <div class="flex items-center space-x-2 shrink-0">
                        <button wire:click="exportCsv"
                                class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs flex items-center space-x-1.5 transition cursor-pointer">
                            <span>📥</span>
                            <span>{{ __('cbvh.export_csv') }}</span>
                        </button>
                        <button wire:click="exportDrinkTotalsCsv"
                                class="px-4 py-2 bg-[#9E6B55] hover:bg-[#835440] text-white font-bold text-xs rounded-xl shadow-xs flex items-center space-x-1.5 transition cursor-pointer">
                            <span>📊</span>
                            <span>{{ __('cbvh.export_drink_totals') }}</span>
                        </button>
                    </div>
                </div>

                <!-- Row 2: Comprehensive Multi-Filter (Search + Branch + Staff + Type + Status + From Date + To Date) -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-2.5 pt-2 border-t border-[#FAF6F3] dark:border-[#3D2F29]">
                    <!-- Live Search -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">🔍 {{ __('cbvh.search') }}</label>
                        <input type="text" wire:model.live.debounce.250ms="searchQuery" placeholder="{{ __('cbvh.search_placeholder') }}"
                               class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]" />
                    </div>

                    <!-- Branch Filter -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">🏢 {{ __('cbvh.branch') }}</label>
                        <select wire:model.live="filterBranchId" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]">
                            <option value="all">{{ __('cbvh.all_branches') }}</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ \App\Support\CbvhLocale::branch($b->name) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Staff / Requester Filter -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">👤 {{ __('cbvh.col_requester') }}</label>
                        <select wire:model.live="filterUserId" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]">
                            <option value="all">{{ __('cbvh.all_staff') }}</option>
                            @foreach($filterUsers as $u)
                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->branch?->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Order Type Filter -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">📋 {{ __('cbvh.order_type') }}</label>
                        <select wire:model.live="filterType" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]">
                            <option value="all">{{ __('cbvh.all_types') }}</option>
                            <option value="instant">⚡ {{ __('cbvh.quick_drink') }}</option>
                            <option value="meeting">📅 {{ __('cbvh.meeting_drink') }}</option>
                            <option value="extend">🔄 {{ __('cbvh.reason_spilled') }}</option>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">🏷️ {{ __('cbvh.status') }}</label>
                        <select wire:model.live="filterStatus" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]">
                            <option value="all">{{ __('cbvh.all_statuses') }}</option>
                            <option value="approved">{{ __('cbvh.status_approved') }}</option>
                            <option value="preparing">{{ __('cbvh.status_preparing') }}</option>
                            <option value="in_delivery">{{ __('cbvh.status_in_delivery') }}</option>
                            <option value="completed">{{ __('cbvh.status_completed') }}</option>
                            <option value="rejected_by_barista">{{ __('cbvh.status_rejected') }}</option>
                        </select>
                    </div>

                    <!-- From Date -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">📅 {{ __('cbvh.from_date') }}</label>
                        <input type="date" wire:model.live="dateFrom" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]" />
                    </div>

                    <!-- To Date -->
                    <div>
                        <label class="block text-[10px] font-bold uppercase text-[#7C6A60] mb-1">📅 {{ __('cbvh.to_date') }}</label>
                        <input type="date" wire:model.live="dateTo" class="w-full text-xs p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-semibold text-[#2C211C] dark:text-[#F5EDE7]" />
                    </div>
                </div>
            </div>

            <!-- Drink Totals Summary Breakdown -->
            @if(!empty($drinkTotals))
                <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xs space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#FAF6F3] dark:border-[#3D2F29] pb-2.5">
                        <div class="flex items-center space-x-2">
                            <span class="text-base">☕</span>
                            <h3 class="text-xs font-black uppercase tracking-wider text-[#2C211C] dark:text-[#F5EDE7]">
                                {{ __('cbvh.drink_summary_title') }}
                            </h3>
                            <span class="text-[11px] font-bold text-[#9E6B55] bg-[#F5EDE7] dark:bg-[#3A2C26] px-2.5 py-0.5 rounded-full">
                                {{ $totalCups }} {{ __('cbvh.total_cups') }}
                            </span>
                        </div>
                        <label class="inline-flex items-center space-x-2 text-[11px] font-bold text-[#7C6A60] cursor-pointer select-none">
                            <input type="checkbox" wire:model.live="showOptionsInTable" class="rounded text-[#9E6B55] focus:ring-[#9E6B55]" />
                            <span>{{ __('cbvh.show_recipe_details') }}</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-2.5">
                        @foreach($drinkTotals as $baseName => $stat)
                            <div class="p-3 rounded-xl bg-[#FAF6F3] dark:bg-[#1E1715] border border-[#E8DDD6]/60 dark:border-[#3D2F29] flex items-center justify-between shadow-2xs">
                                <div class="min-w-0 pr-2">
                                    <div class="font-black text-xs text-[#2C211C] dark:text-[#F5EDE7] truncate">
                                        {{ \App\Support\CbvhLocale::baseDrink($baseName) }}
                                    </div>
                                    <div class="text-[9px] uppercase tracking-wider text-[#7C6A60]">
                                        {{ $stat['category'] }}
                                    </div>
                                </div>
                                <span class="text-sm font-black px-2.5 py-1 rounded-lg bg-[#9E6B55] text-white shrink-0 shadow-2xs">
                                    {{ $stat['cups'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Report Table -->
            <div class="bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xs overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-[#E8DDD6] bg-[#FAF6F3] text-[#7C6A60] uppercase text-[10px] tracking-wider">
                            <th class="py-3 px-4">{{ __('cbvh.col_order') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_type') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_branch_room') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_requester') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_drinks') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_target_time') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_attachment') }}</th>
                            <th class="py-3 px-4">{{ __('cbvh.col_status') }}</th>
                            <th class="py-3 px-4 text-right">{{ __('cbvh.col_action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#FAF6F3] dark:divide-[#3D2F29]">
                        @forelse($orders as $order)
                            <tr class="hover:bg-[#FAF6F3]/60 transition">
                                <td class="py-3.5 px-4 font-bold whitespace-nowrap">#{{ $order->id }}</td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase
                                        {{ $order->order_type === 'instant' ? 'bg-amber-100 text-amber-800' : ($order->order_type === 'extend' ? 'bg-purple-100 text-purple-800' : 'bg-[#F5EDE7] text-[#835440]') }}">
                                        {{ $order->order_type === 'extend' ? 'EXTEND #' . $order->parent_order_id : $order->order_type }}
                                    </span>
                                    @if($order->extend_reason)
                                        <div class="text-[10px] text-purple-700 mt-0.5">{{ __('cbvh.reason_' . $order->extend_reason) }}</div>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold">{{ \App\Support\CbvhLocale::room($order->room?->name) }}</div>
                                    <div class="text-[10px] text-[#7C6A60]">{{ $order->branch?->code }}</div>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-semibold">{{ $order->user?->name }}</div>
                                    <div class="text-[10px] text-[#7C6A60]">{{ $order->people_count }} {{ __('cbvh.pax') }}</div>
                                </td>
                                <td class="py-3.5 px-4 space-y-0.5 max-w-xs">
                                    @if($showOptionsInTable)
                                        @foreach($order->items as $it)
                                            <div>
                                                <strong>{{ $it->quantity }}×</strong> {{ \App\Support\CbvhLocale::drink($it->drinkItem?->name) }}
                                                @if(!empty($it->selected_options))
                                                    <span class="text-[10px] text-[#7C6A60]">({{ collect($it->selected_options)->map(fn($v) => \App\Support\CbvhLocale::option($v))->implode(', ') }})</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    @else
                                        @php
                                            $groupedDrinks = $order->items->groupBy(fn($it) => \App\Support\CbvhLocale::toBaseDrinkName($it->drinkItem?->name));
                                        @endphp
                                        @foreach($groupedDrinks as $bName => $gItems)
                                            <div class="font-bold text-xs text-[#2C211C] dark:text-[#F5EDE7]">
                                                <span class="text-[#9E6B55] font-black mr-1">{{ $gItems->sum('quantity') }}×</span>
                                                {{ \App\Support\CbvhLocale::baseDrink($bName) }}
                                            </div>
                                        @endforeach
                                    @endif
                                    @if($order->notes)
                                        <div class="text-[10px] italic text-[#9E6B55]">"{{ $order->notes }}"</div>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div>{{ $order->delivery_time ? \App\Support\CbvhLocale::formatDateTime($order->delivery_time) : '-' }}</div>
                                    <div class="text-[10px] text-[#7C6A60]">{{ \App\Support\CbvhLocale::formatDateTime($order->created_at) }}</div>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @if($order->attachment_path)
                                        <a href="{{ Storage::url($order->attachment_path) }}" target="_blank"
                                           class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg bg-[#F5EDE7] text-[#835440] font-bold text-[11px] hover:bg-[#E8CFC1]">
                                            <span>📎</span>
                                            <span class="max-w-[100px] truncate">{{ $order->attachment_name }}</span>
                                        </a>
                                    @else
                                        <span class="text-[#7C6A60]/50">—</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase
                                        {{ $order->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : ($order->status === 'in_delivery' ? 'bg-blue-100 text-blue-800' : (str_contains($order->status, 'rejected') ? 'bg-rose-100 text-rose-800' : 'bg-[#F5EDE7] text-[#835440]')) }}">
                                        {{ __('cbvh.status_' . ($order->status === 'rejected_by_barista' ? 'rejected' : $order->status)) }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    @if($order->status === 'pending_approval')
                                        <button wire:click="sendLegacyToBarista({{ $order->id }})" class="px-2.5 py-1 bg-emerald-600 text-white rounded-lg text-[10px] font-bold cursor-pointer">
                                            Barista
                                        </button>
                                    @elseif(in_array($order->status, ['approved', 'preparing', 'in_delivery']))
                                        <button wire:click="markOrderCompleted({{ $order->id }})" class="px-2.5 py-1 bg-[#FAF6F3] border border-[#E8DDD6] text-[#2C211C] rounded-lg text-[10px] font-bold cursor-pointer hover:bg-[#F5EDE7]">
                                            {{ __('cbvh.close_order') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-10 text-center text-[#7C6A60]">{{ __('cbvh.no_matching_orders') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <!-- Pagination Links & Per-Page Controls -->
                <div class="p-4 border-t border-[#FAF6F3] dark:border-[#3D2F29] flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center space-x-2 text-xs text-[#7C6A60]">
                        <span>{{ __('cbvh.show') }}</span>
                        <select wire:model.live="perPage" class="text-xs py-1 px-2.5 rounded-lg border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1E1715] font-bold text-[#2C211C] dark:text-[#F5EDE7]">
                            <option value="15">15</option>
                            <option value="30">30</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span>{{ __('cbvh.orders_per_page') }}</span>
                    </div>

                    @if($orders->hasPages())
                        <div class="scale-90 sm:scale-100 origin-right">
                            {{ $orders->links() }}
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if($activeSection === 'team')
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <div class="lg:col-span-2 space-y-6">
                    <!-- Create Staff Account Card -->
                    <div class="bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] p-5 shadow-2xs space-y-3">
                        <h2 class="font-bold text-sm">➕ {{ __('cbvh.add_staff') }}</h2>
                        <form wire:submit="createStaffAccount" class="grid grid-cols-1 sm:grid-cols-5 gap-2.5">
                            <input type="text" wire:model="newName" placeholder="{{ __('cbvh.full_name') }}" required
                                   class="text-xs p-2.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3]" />
                            <input type="text" wire:model="newEmployeeId" placeholder="{{ __('cbvh.employee_id') }} (e.g. A100888)" required
                                   class="text-xs p-2.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3]" />
                            <input type="password" wire:model="newPassword" placeholder="{{ __('cbvh.password') }}" required
                                   class="text-xs p-2.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3]" />
                            <select wire:model="newBranchId" class="text-xs p-2.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3] font-semibold">
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}">{{ $b->code }} {{ $b->is_hq ? '(HQ)' : '' }}</option>
                                @endforeach
                            </select>
                            <div class="flex space-x-1.5">
                                <select wire:model="newRole" class="w-full text-xs p-2.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3] font-semibold">
                                    <option value="employee">Staff</option>
                                    <option value="barista">Barista</option>
                                    <option value="admin">Admin</option>
                                </select>
                                <button type="submit" class="px-3.5 py-2.5 bg-[#9E6B55] hover:bg-[#835440] text-white text-xs font-bold rounded-xl shrink-0 cursor-pointer">
                                    Add
                                </button>
                            </div>
                        </form>
                        @error('newEmployeeId') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        @error('newPassword') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                    </div>

                    <!-- Staff Directory -->
                    <div class="bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xs overflow-hidden">
                        <div class="px-5 py-4 border-b border-[#E8DDD6] dark:border-[#3D2F29] flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 class="font-bold text-sm">👥 {{ __('cbvh.team_title') }}</h2>
                                <p class="text-xs text-[#7C6A60] mt-0.5">{{ __('cbvh.team_subtitle') }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="text" wire:model.live.debounce.300ms="staffSearch"
                                       placeholder="🔍 Search name / ID (e.g. A100888)..."
                                       class="text-xs px-3 py-1.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] focus:border-[#9E6B55] focus:outline-hidden w-44 sm:w-56" />
                                <select wire:model.live="staffPerPage"
                                        class="text-xs px-2.5 py-1.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] font-semibold text-[#2C211C] dark:text-[#F5EDE7]">
                                    <option value="10">10 / page</option>
                                    <option value="25">25 / page</option>
                                    <option value="50">50 / page</option>
                                    <option value="100">100 / page</option>
                                </select>
                            </div>
                        </div>

                        <div class="divide-y divide-[#FAF6F3] dark:divide-[#3D2F29]">
                            @forelse($users as $u)
                                <div class="p-4 flex flex-wrap items-center justify-between gap-3 text-xs">
                                    <div class="min-w-[180px]">
                                        <div class="font-bold text-[#2C211C] dark:text-[#F5EDE7] flex items-center space-x-1.5">
                                            <span>{{ $u->name }}</span>
                                            @if($u->telegram_chat_id)
                                                <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-full text-[10px] font-bold">{{ __('cbvh.telegram_linked') }}</span>
                                            @else
                                                <span class="px-2 py-0.5 bg-slate-100 text-slate-500 rounded-full text-[10px]">{{ __('cbvh.not_linked') }}</span>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-[#7C6A60]">
                                            <span class="font-mono font-semibold text-[#835440] dark:text-[#E8CFC1]">{{ $u->employee_id ?: 'NO ID' }}</span>
                                            {{ $u->telegram_username ? '• @'.$u->telegram_username : ($u->email ? '• '.$u->email : '') }}
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <select wire:change="updateUserRole({{ $u->id }}, $event.target.value)"
                                                class="px-2.5 py-1.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3] font-bold text-xs text-[#2C211C]">
                                            @foreach([
                                                'employee' => 'Employee',
                                                'barista' => 'Barista',
                                                'admin' => 'Admin',
                                            ] as $rKey => $rLabel)
                                                <option value="{{ $rKey }}" @selected($u->role === $rKey)>{{ $rLabel }}</option>
                                            @endforeach
                                        </select>

                                        <select wire:change="updateUserBranch({{ $u->id }}, $event.target.value)"
                                                class="px-2.5 py-1.5 rounded-xl border border-[#E8DDD6] bg-[#FAF6F3] font-semibold text-xs text-[#2C211C]">
                                            @foreach($branches as $b)
                                                <option value="{{ $b->id }}" @selected($u->branch_id === $b->id)>{{ $b->code }} {{ $b->is_hq ? '(HQ)' : '' }}</option>
                                            @endforeach
                                        </select>

                                        @if($u->telegram_chat_id)
                                            <button wire:click="sendTestTelegram({{ $u->id }})"
                                                    class="px-2.5 py-1.5 rounded-xl bg-[#F5EDE7] hover:bg-[#9E6B55] text-[#835440] hover:text-white font-bold text-[11px] transition cursor-pointer">
                                                {{ __('cbvh.test_ping') }}
                                            </button>
                                            <button wire:click="unlinkTelegram({{ $u->id }})"
                                                    class="px-2.5 py-1.5 rounded-xl bg-rose-50 text-rose-600 font-bold text-[11px] cursor-pointer">
                                                {{ __('cbvh.unlink') }}
                                            </button>
                                        @endif

                                        <button wire:click="openResetPasswordModal({{ $u->id }})"
                                                title="{{ __('cbvh.reset_password') }}"
                                                class="px-2.5 py-1.5 rounded-xl bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 font-bold text-[11px] transition cursor-pointer flex items-center space-x-1">
                                            <span>🔑</span>
                                            <span>{{ __('cbvh.reset_password') }}</span>
                                        </button>
                                    </div>
                                </div>
                            @empty
                                <div class="p-8 text-center text-xs text-[#7C6A60]">
                                    No staff accounts match your search.
                                </div>
                            @endforelse
                        </div>

                        <!-- Staff Directory Pagination Footer -->
                        @if($users->hasPages() || $users->total() > 0)
                            <div class="p-4 border-t border-[#E8DDD6] dark:border-[#3D2F29] flex flex-wrap items-center justify-between gap-3 text-xs bg-[#FAF6F3]/50 dark:bg-[#1E1715]/50">
                                <div class="text-[#7C6A60] dark:text-[#BBA79D]">
                                    Showing <span class="font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ $users->firstItem() ?? 0 }}</span>–<span class="font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ $users->lastItem() ?? 0 }}</span> of <span class="font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ $users->total() }}</span> staff
                                </div>
                                <div class="scale-90 origin-right">
                                    {{ $users->links() }}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-6">
                    <div class="bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] p-5 shadow-2xs space-y-3">
                        <h3 class="font-bold text-sm">⏰ {{ __('cbvh.working_hours_rule') }}</h3>
                        <p class="text-xs text-[#7C6A60] leading-relaxed">{{ __('cbvh.working_hours_desc') }}</p>
                        <button wire:click="toggleWorkingHoursRule"
                                class="w-full py-3 px-4 rounded-xl font-bold text-xs transition flex items-center justify-between cursor-pointer {{ $enforceWorkingHours ? 'bg-[#9E6B55] text-white' : 'bg-amber-500 text-white' }}">
                            <span>{{ $enforceWorkingHours ? '🔒 ' . __('cbvh.enforced_hours') : '🧪 ' . __('cbvh.testing_24_7') }}</span>
                            <span class="underline text-[11px]">{{ __('cbvh.switch') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif

        @if($activeSection === 'drinks')
            <div class="space-y-6">
                <!-- Top Toolbar & Controls -->
                <div class="bg-white dark:bg-[#241C19] p-5 rounded-3xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xs flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ __('cbvh.drink_mgmt_title') }}</h2>
                        <p class="text-xs text-[#7C6A60] dark:text-[#BBA79D]">{{ __('cbvh.drink_mgmt_subtitle') }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="openCreateDrinkModal"
                                class="px-4 py-2.5 rounded-2xl bg-[#9E6B55] hover:bg-[#835440] text-white font-bold text-xs shadow-md transition cursor-pointer flex items-center space-x-1.5">
                            <span>☕</span>
                            <span>{{ __('cbvh.btn_add_drink') }}</span>
                        </button>
                    </div>
                </div>

                <!-- Filters & Search Bar -->
                <div class="bg-white dark:bg-[#241C19] p-4 rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xs flex flex-wrap items-center gap-3">
                    <div class="flex-1 min-w-[220px]">
                        <input type="text" wire:model.live.debounce.300ms="drinkSearch"
                               placeholder="Search drink name, category..."
                               class="w-full text-xs p-2.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] focus:border-[#9E6B55] focus:outline-hidden" />
                    </div>

                    <div class="flex items-center space-x-2">
                        <label class="text-xs font-semibold text-[#7C6A60] shrink-0">{{ __('cbvh.category') }}:</label>
                        <select wire:model.live="drinkCategoryFilter"
                                class="text-xs p-2.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] font-semibold cursor-pointer">
                            <option value="all">All Categories</option>
                            @foreach($drinkCategories as $cat)
                                <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center space-x-2">
                        <label class="text-xs font-semibold text-[#7C6A60] shrink-0">Branch:</label>
                        <select wire:model.live="drinkBranchFilter"
                                class="text-xs p-2.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] font-semibold cursor-pointer">
                            <option value="all">🌐 {{ __('cbvh.all_branches') }} (Grouped)</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">🏢 {{ $b->code }} {{ $b->is_hq ? '(HQ)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <!-- Drinks Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @forelse($menuDrinks as $drink)
                        <div wire:key="admin-drink-card-{{ $drink->id }}"
                             class="bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] p-5 shadow-2xs hover:shadow-md transition flex flex-col justify-between space-y-4 {{ !$drink->is_available ? 'opacity-70 bg-[#FAF6F3]/50' : '' }}">
                            <div class="space-y-2.5">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="space-y-0.5">
                                        <div class="flex items-center space-x-2">
                                            <h3 class="font-bold text-sm text-[#2C211C] dark:text-[#F5EDE7] leading-snug">
                                                {{ $drink->name }}
                                            </h3>
                                            @if($drink->is_available)
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                    {{ __('cbvh.in_stock') }}
                                                </span>
                                            @else
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300">
                                                    {{ __('cbvh.out_of_stock') }}
                                                </span>
                                            @endif
                                        </div>
                                        @if($drink->name_km)
                                            <div class="text-xs font-semibold text-[#835440] dark:text-[#E8CFC1]">
                                                {{ $drink->name_km }}
                                            </div>
                                        @endif
                                    </div>

                                    <div class="flex items-center space-x-1 shrink-0">
                                        @if(!empty($drink->branch))
                                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                                🏢 {{ $drink->branch->code }}{{ $drink->branch->is_hq ? ' (HQ)' : '' }}
                                            </span>
                                        @elseif(!empty($drink->branch_items))
                                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-purple-50 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200 dark:border-purple-800">
                                                🏢 All Branches
                                            </span>
                                        @endif
                                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-[#F5EDE7] dark:bg-[#3D2F29] text-[#835440] dark:text-[#E8CFC1]">
                                            {{ $drink->category ?: 'drink' }}
                                        </span>
                                    </div>
                                </div>

                                @if($drink->description || $drink->description_km)
                                    <p class="text-xs text-[#7C6A60] dark:text-[#BBA79D] leading-relaxed line-clamp-2">
                                        {{ app()->getLocale() === 'km' && !empty($drink->description_km) ? $drink->description_km : ($drink->description ?: $drink->description_km) }}
                                    </p>
                                @endif

                                <!-- Configured Layers Badges -->
                                @php $layers = $drink->normalized_layers; @endphp
                                @if(!empty($layers))
                                    <div class="pt-2 border-t border-[#FAF6F3] dark:border-[#3D2F29]/60 flex flex-wrap items-center gap-1.5">
                                        @foreach($layers as $lyr)
                                            <span class="inline-flex items-center px-2 py-1 rounded-md text-[10px] font-semibold bg-[#FAF6F3] dark:bg-[#1A1412] text-[#7C6A60] dark:text-[#BBA79D] border border-[#E8DDD6] dark:border-[#3D2F29]">
                                                ⚙️ {{ $lyr['name_en'] }} ({{ count($lyr['options'] ?? []) }})
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <!-- Card Action Buttons -->
                            <div class="pt-3 border-t border-[#E8DDD6] dark:border-[#3D2F29] flex flex-wrap items-center justify-between gap-2">
                                @if(!empty($drink->branch_items))
                                    <!-- Per-branch toggles in grouped mode -->
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach($drink->branch_items as $bItem)
                                            <button type="button" wire:click="toggleDrinkAvailability({{ $bItem->id }})"
                                                    class="px-2.5 py-1.5 rounded-xl text-[11px] font-bold border transition cursor-pointer {{ $bItem->is_available ? 'bg-emerald-50 border-emerald-300 text-emerald-800 hover:bg-emerald-100' : 'bg-rose-50 border-rose-300 text-rose-800 hover:bg-rose-100' }}"
                                                    title="{{ $bItem->branch_name }}">
                                                {{ $bItem->branch_code }}: {{ $bItem->is_available ? '🟢 ' . __('cbvh.in_stock') : '🔴 ' . __('cbvh.out_of_stock') }}
                                            </button>
                                        @endforeach
                                    </div>
                                @else
                                    <!-- Single branch toggle -->
                                    <button type="button" wire:click="toggleDrinkAvailability({{ $drink->id }})"
                                            class="px-2.5 py-1.5 rounded-xl text-[11px] font-bold border transition cursor-pointer {{ $drink->is_available ? 'bg-emerald-50 border-emerald-300 text-emerald-800 hover:bg-emerald-100' : 'bg-rose-50 border-rose-300 text-rose-800 hover:bg-rose-100' }}"
                                            title="{{ __('cbvh.toggle_stock') }}">
                                        {{ $drink->is_available ? '🟢 ' . __('cbvh.in_stock') : '🔴 ' . __('cbvh.out_of_stock') }}
                                    </button>
                                @endif

                                <div class="flex items-center space-x-1.5">
                                    <button type="button" wire:click="openEditDrinkModal({{ $drink->id }})"
                                            class="px-3 py-1.5 rounded-xl bg-[#FAF6F3] dark:bg-[#1A1412] hover:bg-[#F5EDE7] text-[#835440] dark:text-[#E8CFC1] font-bold text-xs border border-[#E8DDD6] dark:border-[#3D2F29] transition cursor-pointer">
                                        ✏️ {{ __('cbvh.edit_drink') }}
                                    </button>
                                    @if(!empty($drink->branch_items))
                                        <button type="button" wire:click="deleteDrinkGroup('{{ addslashes($drink->name) }}')"
                                                wire:confirm="{{ __('cbvh.confirm_delete_drink') }}"
                                                class="p-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 hover:bg-rose-100 transition cursor-pointer text-xs"
                                                title="Delete Drink Across All Branches">
                                            🗑️
                                        </button>
                                    @else
                                        <button type="button" wire:click="deleteDrink({{ $drink->id }})"
                                                wire:confirm="{{ __('cbvh.confirm_delete_drink') }}"
                                                class="p-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 hover:bg-rose-100 transition cursor-pointer text-xs"
                                                title="Delete Drink">
                                            🗑️
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-span-full bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] p-10 text-center space-y-3">
                            <div class="text-3xl">☕</div>
                            <div class="text-sm font-bold text-[#2C211C] dark:text-[#F5EDE7]">No drinks found</div>
                            <p class="text-xs text-[#7C6A60] dark:text-[#BBA79D]">Click "+ Add New Drink" above to create custom bilingual items with flexible layers.</p>
                        </div>
                    @endforelse
                </div>

                <!-- Pagination Footer -->
                @if($menuDrinks->hasPages() || $menuDrinks->total() > 0)
                    <div class="p-4 bg-white dark:bg-[#241C19] rounded-2xl border border-[#E8DDD6] dark:border-[#3D2F29] flex flex-wrap items-center justify-between gap-3 text-xs">
                        <div class="text-[#7C6A60] dark:text-[#BBA79D]">
                            Showing <span class="font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ $menuDrinks->firstItem() ?? 0 }}</span>–<span class="font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ $menuDrinks->lastItem() ?? 0 }}</span> of <span class="font-bold text-[#2C211C] dark:text-[#F5EDE7]">{{ $menuDrinks->total() }}</span> drinks
                        </div>
                        <div class="scale-90 origin-right">
                            {{ $menuDrinks->links() }}
                        </div>
                    </div>
                @endif
            </div>
        @endif

    </main>

    <!-- Create / Edit Drink Modal with Dynamic Layers Builder -->
    @if($showDrinkModal)
        <div class="fixed inset-0 bg-[#2C211C]/60 backdrop-blur-xs z-50 flex items-center justify-center p-4 overflow-y-auto"
             wire:keydown.escape="closeDrinkModal">
            <div class="bg-white dark:bg-[#241C19] w-full max-w-2xl rounded-3xl p-6 border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xl space-y-5 my-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between border-b border-[#E8DDD6] dark:border-[#3D2F29] pb-3">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-2xl bg-[#F5EDE7] dark:bg-[#3D2F29] text-[#835440] dark:text-[#E8CFC1] flex items-center justify-center text-xl shrink-0">
                            ☕
                        </div>
                        <div>
                            <h3 class="font-bold text-sm text-[#2C211C] dark:text-[#F5EDE7]">
                                {{ $editingDrinkId ? __('cbvh.edit_drink') : __('cbvh.btn_add_drink') }}
                            </h3>
                            <p class="text-xs text-[#7C6A60] dark:text-[#BBA79D]">
                                {{ __('cbvh.drink_mgmt_subtitle') }}
                            </p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeDrinkModal" class="w-8 h-8 rounded-full bg-[#FAF6F3] dark:bg-[#3A2C26] text-[#7C6A60] hover:text-[#2C211C] flex items-center justify-center text-lg font-bold cursor-pointer">&times;</button>
                </div>

                <form wire:submit="saveDrink" class="space-y-4">
                    <!-- Bilingual Drink Name -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[#7C6A60] dark:text-[#BBA79D] mb-1">
                                {{ __('cbvh.drink_name_en') }} <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" wire:model="drinkNameEn" required placeholder="e.g. Milk Tea"
                                   class="w-full text-xs p-2.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] focus:border-[#9E6B55] focus:outline-hidden" />
                            @error('drinkNameEn') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-[#7C6A60] dark:text-[#BBA79D] mb-1">
                                {{ __('cbvh.drink_name_km') }} (Khmer)
                            </label>
                            <input type="text" wire:model="drinkNameKm" placeholder="ឧ. តែទឹកដោះគោ"
                                   class="w-full text-xs p-2.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] focus:border-[#9E6B55] focus:outline-hidden" />
                            @error('drinkNameKm') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- Category & Branch -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[#7C6A60] dark:text-[#BBA79D] mb-1">
                                {{ __('cbvh.category') }} <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" wire:model="drinkCategory" required placeholder="e.g. tea, coffee, special"
                                   class="w-full text-xs p-2.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] focus:border-[#9E6B55] focus:outline-hidden" />
                            @error('drinkCategory') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-[#7C6A60] dark:text-[#BBA79D] mb-1">
                                Target Branches <span class="text-rose-500">*</span>
                            </label>
                            <div class="flex flex-wrap items-center gap-2 pt-0.5">
                                @foreach($branches as $b)
                                    <label class="flex items-center space-x-1.5 text-xs font-bold cursor-pointer bg-[#FAF6F3] dark:bg-[#1A1412] px-2.5 py-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29]">
                                        <input type="checkbox" value="{{ $b->id }}" wire:model="drinkTargetBranches"
                                               class="rounded text-[#9E6B55] focus:ring-[#9E6B55]">
                                        <span>🏢 {{ $b->code }}{{ $b->is_hq ? ' (HQ)' : '' }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('drinkTargetBranches') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- Bilingual Descriptions -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[#7C6A60] dark:text-[#BBA79D] mb-1">
                                {{ __('cbvh.drink_desc_en') }}
                            </label>
                            <textarea wire:model="drinkDescriptionEn" rows="2" placeholder="e.g. Classic Taiwanese style brown sugar milk tea"
                                      class="w-full text-xs p-2.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] focus:border-[#9E6B55] focus:outline-hidden"></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-[#7C6A60] dark:text-[#BBA79D] mb-1">
                                {{ __('cbvh.drink_desc_km') }}
                            </label>
                            <textarea wire:model="drinkDescriptionKm" rows="2" placeholder="ឧ. តែទឹកដោះគោស្ករត្នោតបែបបុរាណតៃវ៉ាន់"
                                      class="w-full text-xs p-2.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] focus:border-[#9E6B55] focus:outline-hidden"></textarea>
                        </div>
                    </div>

                    <!-- Stock Availability Checkbox -->
                    <div class="flex items-center space-x-2.5 p-3 rounded-xl bg-[#FAF6F3] dark:bg-[#1E1715] border border-[#E8DDD6] dark:border-[#3D2F29]">
                        <input type="checkbox" id="drinkIsAvailableCheckbox" wire:model="drinkIsAvailable"
                               class="w-4 h-4 text-[#9E6B55] rounded-sm focus:ring-[#9E6B55] cursor-pointer" />
                        <label for="drinkIsAvailableCheckbox" class="text-xs font-bold text-[#2C211C] dark:text-[#F5EDE7] cursor-pointer">
                            {{ __('cbvh.in_stock') }} ({{ __('cbvh.toggle_stock') }})
                        </label>
                    </div>

                    <!-- Dynamic Layer Builder -->
                    <div class="space-y-3 pt-2 border-t border-[#E8DDD6] dark:border-[#3D2F29]">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h4 class="font-bold text-xs uppercase tracking-wider text-[#9E6B55]">
                                    {{ __('cbvh.custom_layers_title') }}
                                </h4>
                                <p class="text-[11px] text-[#7C6A60] dark:text-[#BBA79D]">
                                    {{ __('cbvh.custom_layers_desc') }}
                                </p>
                            </div>

                            <!-- Quick Preset Buttons -->
                            <div class="flex flex-wrap items-center gap-1.5">
                                <button type="button" wire:click="addSugarPreset"
                                        class="px-2.5 py-1 text-[10px] font-bold rounded-lg bg-[#F5EDE7] hover:bg-[#9E6B55] text-[#835440] hover:text-white border border-[#E8DDD6] transition cursor-pointer">
                                    {{ __('cbvh.preset_sugar') }}
                                </button>
                                <button type="button" wire:click="addIcePreset"
                                        class="px-2.5 py-1 text-[10px] font-bold rounded-lg bg-[#F5EDE7] hover:bg-[#9E6B55] text-[#835440] hover:text-white border border-[#E8DDD6] transition cursor-pointer">
                                    {{ __('cbvh.preset_ice') }}
                                </button>
                                <button type="button" wire:click="addStrengthPreset"
                                        class="px-2.5 py-1 text-[10px] font-bold rounded-lg bg-[#F5EDE7] hover:bg-[#9E6B55] text-[#835440] hover:text-white border border-[#E8DDD6] transition cursor-pointer">
                                    {{ __('cbvh.preset_strength') }}
                                </button>
                                <button type="button" wire:click="addShakePreset"
                                        class="px-2.5 py-1 text-[10px] font-bold rounded-lg bg-[#F5EDE7] hover:bg-[#9E6B55] text-[#835440] hover:text-white border border-[#E8DDD6] transition cursor-pointer">
                                    {{ __('cbvh.preset_shake') }}
                                </button>
                                <button type="button" wire:click="addLayer"
                                        class="px-2.5 py-1 text-[10px] font-bold rounded-lg bg-emerald-50 hover:bg-emerald-600 text-emerald-800 hover:text-white border border-emerald-200 transition cursor-pointer">
                                    {{ __('cbvh.btn_add_layer') }}
                                </button>
                            </div>
                        </div>

                        <!-- Layer Items Container -->
                        <div class="space-y-3">
                            @forelse($drinkLayers as $lIdx => $layer)
                                <div wire:key="drink-layer-card-{{ $lIdx }}"
                                     class="p-3.5 rounded-2xl bg-[#FAF6F3]/80 dark:bg-[#1E1715]/80 border border-[#E8DDD6] dark:border-[#3D2F29] space-y-3">
                                    <!-- Layer Header: Name EN & Name KM -->
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="grid grid-cols-2 gap-2 flex-1">
                                            <input type="text" wire:model="drinkLayers.{{ $lIdx }}.name_en"
                                                   placeholder="{{ __('cbvh.layer_name_en') }}"
                                                   class="text-xs p-2 rounded-lg border border-[#E8DDD6] dark:border-[#3D2F29] bg-white dark:bg-[#241C19] text-[#2C211C] dark:text-[#F5EDE7] font-bold focus:border-[#9E6B55] focus:outline-hidden" />
                                            <input type="text" wire:model="drinkLayers.{{ $lIdx }}.name_km"
                                                   placeholder="{{ __('cbvh.layer_name_km') }}"
                                                   class="text-xs p-2 rounded-lg border border-[#E8DDD6] dark:border-[#3D2F29] bg-white dark:bg-[#241C19] text-[#2C211C] dark:text-[#F5EDE7] font-semibold focus:border-[#9E6B55] focus:outline-hidden" />
                                        </div>
                                        <button type="button" wire:click="removeLayer({{ $lIdx }})"
                                                class="px-2 py-1.5 rounded-lg text-[11px] font-bold bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 transition cursor-pointer shrink-0"
                                                title="{{ __('cbvh.btn_remove_layer') }}">
                                            🗑️
                                        </button>
                                    </div>

                                    <!-- Choices List -->
                                    <div class="space-y-1.5 pl-2 border-l-2 border-[#9E6B55]/30">
                                        <div class="flex items-center justify-between text-[11px] font-semibold text-[#7C6A60] mb-1">
                                            <span>Choices:</span>
                                            <button type="button" wire:click="addChoice({{ $lIdx }})"
                                                    class="text-[10px] font-bold text-[#835440] hover:underline cursor-pointer">
                                                {{ __('cbvh.btn_add_choice') }}
                                            </button>
                                        </div>
                                        @foreach($layer['options'] ?? [] as $cIdx => $choice)
                                            <div wire:key="drink-choice-{{ $lIdx }}-{{ $cIdx }}"
                                                 class="flex items-center space-x-2">
                                                <input type="text" wire:model="drinkLayers.{{ $lIdx }}.options.{{ $cIdx }}.en"
                                                       placeholder="{{ __('cbvh.choice_en') }}"
                                                       class="flex-1 text-xs p-1.5 rounded-lg border border-[#E8DDD6] dark:border-[#3D2F29] bg-white dark:bg-[#241C19] text-[#2C211C] dark:text-[#F5EDE7] focus:outline-hidden" />
                                                <input type="text" wire:model="drinkLayers.{{ $lIdx }}.options.{{ $cIdx }}.km"
                                                       placeholder="{{ __('cbvh.choice_km') }}"
                                                       class="flex-1 text-xs p-1.5 rounded-lg border border-[#E8DDD6] dark:border-[#3D2F29] bg-white dark:bg-[#241C19] text-[#2C211C] dark:text-[#F5EDE7] focus:outline-hidden" />
                                                <button type="button" wire:click="removeChoice({{ $lIdx }}, {{ $cIdx }})"
                                                        class="w-6 h-6 rounded-md bg-rose-50 hover:bg-rose-100 text-rose-600 flex items-center justify-center text-xs font-bold cursor-pointer shrink-0"
                                                        title="{{ __('cbvh.btn_remove_choice') }}">
                                                    &times;
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @empty
                                <div class="p-4 rounded-xl border border-dashed border-[#E8DDD6] text-center text-xs text-[#7C6A60]">
                                    No custom layers added. Click a preset button above to add layers like Sweetness, Ice, or Strength.
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <!-- Modal Actions -->
                    <div class="grid grid-cols-2 gap-3 pt-3 border-t border-[#E8DDD6] dark:border-[#3D2F29]">
                        <button type="button" wire:click="closeDrinkModal"
                                class="py-2.5 px-4 rounded-xl bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7] font-bold text-xs cursor-pointer hover:bg-[#F5EDE7] transition">
                            Cancel
                        </button>
                        <button type="submit"
                                class="py-2.5 px-4 rounded-xl bg-[#9E6B55] hover:bg-[#835440] text-white font-bold text-xs shadow-md cursor-pointer transition">
                            💾 Save Drink
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Reset Password Modal -->
    @if($showResetPasswordModal)
        <div class="fixed inset-0 bg-[#2C211C]/60 backdrop-blur-xs z-50 flex items-center justify-center p-4"
             wire:keydown.escape="closeResetPasswordModal">
            <div class="bg-white dark:bg-[#241C19] w-full max-w-sm rounded-3xl p-6 border border-[#E8DDD6] dark:border-[#3D2F29] shadow-2xl space-y-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-100 dark:bg-amber-950/50 text-amber-600 flex items-center justify-center text-xl shrink-0">
                        🔑
                    </div>
                    <div>
                        <h3 class="font-bold text-sm text-[#2C211C] dark:text-[#F5EDE7]">
                            {{ __('cbvh.reset_password') }}
                        </h3>
                        <p class="text-xs text-[#7C6A60] dark:text-[#BBA79D]">
                            {{ $resettingUserName }} ({{ $resettingUserEmployeeId }})
                        </p>
                    </div>
                </div>

                <form wire:submit="saveNewPassword" class="space-y-3 pt-2">
                    <div>
                        <label class="block text-xs font-semibold text-[#7C6A60] dark:text-[#BBA79D] mb-1">
                            {{ __('cbvh.new_password') }}
                        </label>
                        <input type="text" wire:model="newStaffResetPassword" required minlength="4"
                               class="w-full text-xs p-2.5 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29] bg-[#FAF6F3] dark:bg-[#1A1412] text-[#2C211C] dark:text-[#F5EDE7] font-mono focus:border-[#9E6B55] focus:outline-hidden" />
                        @error('newStaffResetPassword') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex items-center justify-between text-[11px] bg-[#FAF6F3] dark:bg-[#1E1715] p-2 rounded-xl border border-[#E8DDD6] dark:border-[#3D2F29]">
                        <span class="text-[#7C6A60] dark:text-[#BBA79D]">Quick default:</span>
                        <button type="button" wire:click="$set('newStaffResetPassword', 'cbvh1234')"
                                class="font-mono font-bold text-amber-600 dark:text-amber-400 hover:underline cursor-pointer">
                            {{ __('cbvh.reset_to_default') }}
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-2 pt-2">
                        <button type="button" wire:click="closeResetPasswordModal"
                                class="py-2.5 px-3 rounded-xl bg-[#FAF6F3] dark:bg-[#1A1412] border border-[#E8DDD6] dark:border-[#3D2F29] text-[#2C211C] dark:text-[#F5EDE7] font-bold text-xs cursor-pointer hover:bg-[#F5EDE7] transition">
                            Cancel
                        </button>
                        <button type="submit"
                                class="py-2.5 px-3 rounded-xl bg-[#9E6B55] hover:bg-[#835440] text-white font-bold text-xs shadow-xs cursor-pointer transition">
                            {{ __('cbvh.save_password') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <x-logout-confirm-modal mode="form" />
</div>
