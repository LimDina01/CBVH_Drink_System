<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DrinkItem;
use App\Models\DrinkOrder;
use App\Models\DrinkOrderItem;
use App\Models\RecurringSchedule;
use App\Models\RecurringScheduleDate;
use App\Models\RecurringScheduleItem;
use App\Models\Room;
use App\Models\User;
use App\Services\RecurringOrderService;
use App\Services\TelegramService;
use App\Support\CbvhLocale;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BookingSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unauthenticated_users_redirect_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/barista')->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');

        $this->get('/login')
            ->assertStatus(200)
            ->assertSee('Staff Sign In');
    }

    public function test_role_permissions_for_employee_barista_and_admin(): void
    {
        $employee = User::where('email', 'employee@company.com')->first();
        $barista = User::where('email', 'barista@company.com')->first();
        $admin = User::where('email', 'admin@company.com')->first();

        $this->actingAs($employee)->get('/')->assertStatus(200);
        $this->actingAs($employee)->get('/barista')->assertStatus(403);
        $this->actingAs($employee)->get('/admin')->assertStatus(403);

        $this->actingAs($barista)->get('/barista')->assertStatus(200);
        $this->actingAs($barista)->get('/admin')->assertStatus(403);

        $this->actingAs($admin)->get('/admin')->assertStatus(200);
        $this->actingAs($admin)->get('/barista')->assertStatus(200);
        $this->actingAs($admin)->get('/')->assertStatus(200);
    }

    public function test_first_time_login_binds_telegram_and_auto_logs_in_thereafter(): void
    {
        $botToken = config('services.telegram.bot_token') ?: 'test-token-123';
        config(['services.telegram.bot_token' => $botToken]);

        $employee = User::where('email', 'branch.staff@company.com')->first();
        $tgUserJson = json_encode(['id' => 77889900, 'first_name' => 'Dara', 'username' => 'darasok']);
        $fields = ['auth_date' => (string) time(), 'user' => $tgUserJson];
        ksort($fields);
        $hash = hash_hmac('sha256', "auth_date={$fields['auth_date']}\nuser={$fields['user']}", hash_hmac('sha256', $botToken, 'WebAppData', true));
        $initData = http_build_query($fields + ['hash' => $hash]);

        // First-time sign in binds Telegram ID
        Livewire::test('auth.login-page')
            ->set('telegramInitData', $initData)
            ->set('email', $employee->email)
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect('/');

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'telegram_chat_id' => '77889900',
            'telegram_username' => 'darasok',
        ]);

        auth()->logout();

        // Subsequent open inside Telegram auto-logs in without password
        Livewire::test('auth.login-page')
            ->call('attemptTelegramAutoLogin', $initData)
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($employee->fresh());

        // Unlink & Sign Out must clear both chat_id and username so Telegram does not auto-login back in
        Livewire::actingAs($employee->fresh())
            ->test('mini-app.booking-app')
            ->call('logout', true)
            ->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'telegram_chat_id' => null,
            'telegram_username' => null,
        ]);

        Livewire::test('auth.login-page')
            ->call('attemptTelegramAutoLogin', $initData)
            ->assertNoRedirect()
            ->assertSet('telegramInitData', $initData);

        $this->assertGuest();
    }

    public function test_language_switch_toggles_between_english_and_khmer_cleanly(): void
    {
        $admin = User::where('email', 'admin@company.com')->first();

        $this->actingAs($admin)->get('/lang/km')->assertRedirect();
        $this->actingAs($admin)->get('/barista')
            ->assertStatus(200)
            ->assertSee('អេក្រង់កន្លែងឆុងភេសជ្ជៈ')
            ->assertDontSee('Barista Kitchen Display');

        $this->actingAs($admin)->get('/lang/en')->assertRedirect();
        $this->actingAs($admin)->get('/barista')
            ->assertStatus(200)
            ->assertSee('Barista Kitchen Display')
            ->assertDontSee('អេក្រង់កន្លែងឆុងភេសជ្ជៈ');
    }

    public function test_mini_app_screen_loads_with_safe_areas_and_no_dark_auto_mode(): void
    {
        $employee = User::where('email', 'employee@company.com')->first();

        $response = $this->actingAs($employee)->get('/');
        $response->assertStatus(200);
        $response->assertSee('CBVH Drink System');
        $response->assertSee('cbvh-mark.png');
        $response->assertSee('disableVerticalSwipes()', false);
        $response->assertSee('requestFullscreen()', false);
        $response->assertSee('cbvh-safe-header', false);
        $response->assertSee('cbvh-safe-bottom-nav', false);
        $response->assertDontSee('type="datetime-local"', false);
        $response->assertDontSee("tg.colorScheme === 'dark'", false);
    }

    public function test_hq_user_can_order_quick_drink_with_two_minute_per_cup_wait(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        $hq = Branch::where('is_hq', true)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $lounge = Room::where('branch_id', $hq->id)->where('name', 'Showroom')->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->set('selectedRoomId', $lounge->id)
            ->call('setOrderType', 'instant')
            ->assertDontSee(__('cbvh.people_count'))
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 3)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('activeTab', 'history');

        $this->assertDatabaseHas('drink_orders', [
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $lounge->id,
            'order_type' => 'instant',
            'estimated_wait_minutes' => 6,
            'people_count' => 3,
            'status' => 'approved',
        ]);
    }

    public function test_quick_drink_blocked_outside_working_hours(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 19, 30, 0));

        $hq = Branch::where('is_hq', true)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'instant')
            ->call('selectDrink', $drink->id)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'error');

        $this->assertDatabaseCount('drink_orders', 0);
    }

    public function test_branch_user_locked_to_own_branch_and_meeting_only(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        $hq = Branch::where('is_hq', true)->first();
        $branchUser = User::where('email', 'branch.staff@company.com')->first();

        Livewire::actingAs($branchUser)
            ->test('mini-app.booking-app')
            ->assertSet('selectedBranchId', $branchUser->branch_id)
            ->assertSet('orderType', 'meeting')
            ->set('selectedBranchId', $hq->id)
            ->assertSet('selectedBranchId', $branchUser->branch_id)
            ->call('setOrderType', 'instant')
            ->assertSet('flashType', 'error');
    }

    public function test_meeting_drink_enforces_one_day_and_4pm_cutoff_and_attachment_hidden_from_barista(): void
    {
        $hq = Branch::where('is_hq', true)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $barista = User::where('email', 'barista@company.com')->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        // Even before 4 PM (e.g. 10:00 AM), tomorrow must be blocked; earliest is day after tomorrow
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'meeting')
            ->assertSet('meetingDate', '2026-11-14')
            ->assertSee('14/Nov/2026')
            ->set('meetingDate', '2026-11-13')
            ->set('meetingClock', '10:00')
            ->call('selectDrink', $drink->id)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'error');

        $this->assertDatabaseCount('drink_orders', 0);

        $file = UploadedFile::fake()->create('guest-list.pdf', 200, 'application/pdf');

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'meeting')
            ->set('meetingDate', '2026-11-14')
            ->set('meetingClock', '12:30')
            ->set('attachment', $file)
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 8)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseHas('drink_orders', [
            'order_type' => 'meeting',
            'people_count' => 8,
            'attachment_name' => 'guest-list.pdf',
            'status' => 'approved',
        ]);

        Livewire::actingAs($barista)
            ->test('barista.kitchen-display')
            ->assertSee($drink->name)
            ->assertDontSee('guest-list.pdf');
    }

    public function test_package_delivery_user_confirm_and_10_minute_auto_complete(): void
    {
        $hq = Branch::where('is_hq', true)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $barista = User::where('email', 'barista@company.com')->first();
        $room = Room::where('branch_id', $hq->id)->first();

        $order = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'approved',
        ]);

        Livewire::actingAs($barista)
            ->test('barista.kitchen-display')
            ->call('acceptOrder', $order->id)
            ->call('markInDelivery', $order->id);

        $this->assertDatabaseHas('drink_orders', [
            'id' => $order->id,
            'status' => 'in_delivery',
        ]);

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('confirmReceived', $order->id);

        $this->assertDatabaseHas('drink_orders', [
            'id' => $order->id,
            'status' => 'completed',
            'auto_completed' => false,
        ]);

        // Non-expired order (e.g. 5 minutes ago) should NOT be auto-completed
        $recentOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'in_delivery',
            'in_delivery_at' => now()->subMinutes(5),
        ]);

        $this->assertSame(0, DrinkOrder::autoCompleteExpiredDeliveries());

        // Expired order (11 minutes ago > 10m threshold) should auto-complete
        $forgotOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'in_delivery',
            'in_delivery_at' => now()->subMinutes(11),
        ]);

        $this->assertSame(1, DrinkOrder::autoCompleteExpiredDeliveries());
        $this->assertDatabaseHas('drink_orders', [
            'id' => $forgotOrder->id,
            'status' => 'completed',
            'auto_completed' => true,
        ]);
        $this->assertDatabaseHas('drink_orders', [
            'id' => $recentOrder->id,
            'status' => 'in_delivery',
        ]);

        // Branch orders require 30 minutes to auto-complete (15m is still in delivery)
        $branch = Branch::where('is_hq', false)->first();
        $branchRoom = Room::where('branch_id', $branch->id)->first();
        $branchOrder15 = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $branch->id,
            'room_id' => $branchRoom->id,
            'order_type' => 'meeting',
            'status' => 'in_delivery',
            'in_delivery_at' => now()->subMinutes(15),
        ]);
        $this->assertSame(0, DrinkOrder::autoCompleteExpiredDeliveries());
        $this->assertEquals('in_delivery', $branchOrder15->fresh()->status);

        // Branch order at 31 minutes should auto-complete
        $branchOrder31 = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $branch->id,
            'room_id' => $branchRoom->id,
            'order_type' => 'meeting',
            'status' => 'in_delivery',
            'in_delivery_at' => now()->subMinutes(31),
        ]);
        $this->assertSame(1, DrinkOrder::autoCompleteExpiredDeliveries());
        $this->assertEquals('completed', $branchOrder31->fresh()->status);
    }

    public function test_extend_order_for_spilled_drink_bypasses_advance_and_hours_rules(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 20, 0, 0));

        $hq = Branch::where('is_hq', true)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $room = Room::where('branch_id', $hq->id)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        $parent = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'meeting',
            'people_count' => 10,
            'status' => 'in_delivery',
            'in_delivery_at' => now(),
        ]);

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('startExtendOrder', $parent->id)
            ->set('extendReason', 'spilled')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 2)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseHas('drink_orders', [
            'order_type' => 'extend',
            'parent_order_id' => $parent->id,
            'extend_reason' => 'spilled',
            'people_count' => 2,
            'status' => 'approved',
        ]);

        $this->assertEquals('completed', $parent->fresh()->status);

        // Completed orders no longer show or allow Order More / Spilled
        $completed = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->set('activeTab', 'history')
            ->assertDontSee(__('cbvh.btn_extend_completed'))
            ->call('startExtendOrder', $completed->id)
            ->assertSet('extendingOrderId', null);
    }

    public function test_spill_replacement_cannot_exceed_parent_order_cup_count(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        $hq = Branch::where('is_hq', true)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $room = Room::where('branch_id', $hq->id)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        // Single cup order in delivery
        $parent = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'people_count' => 1,
            'status' => 'in_delivery',
            'in_delivery_at' => now(),
        ]);
        DrinkOrderItem::create([
            'drink_order_id' => $parent->id,
            'drink_item_id' => $drink->id,
            'quantity' => 1,
            'selected_options' => [],
        ]);

        // Trying to reorder 2 cups for spilled reason on a 1-cup order must be blocked!
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('startExtendOrder', $parent->id)
            ->set('extendReason', 'spilled')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 2)
            ->call('addToCart')
            ->assertSet('flashType', 'error');

        // Parent remains in_delivery because reorder was blocked
        $this->assertEquals('in_delivery', $parent->fresh()->status);

        // Reordering 1 cup succeeds and auto-completes the parent order!
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('startExtendOrder', $parent->id)
            ->set('extendReason', 'spilled')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 1)
            ->call('addToCart')
            ->assertSet('flashType', 'success')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $this->assertEquals('completed', $parent->fresh()->status);
    }

    public function test_kitchen_display_flags_overdue_orders_and_auto_expires_yesterdays_unfulfilled_orders(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        $hq = Branch::where('is_hq', true)->first();
        $barista = User::where('role', 'barista')->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $room = Room::where('branch_id', $hq->id)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        // An order scheduled for 10 minutes ago (overdue)
        $overdueOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'delivery_time' => now()->subMinutes(10),
            'estimated_wait_minutes' => 4,
            'status' => 'approved',
            'approved_at' => now()->subMinutes(14),
        ]);
        $overdueOrder->items()->create([
            'drink_item_id' => $drink->id,
            'quantity' => 1,
            'selected_options' => [],
        ]);

        // A quick order left over from yesterday
        $yesterdayOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'approved',
            'approved_at' => now()->subDay(),
        ]);
        $yesterdayOrder->forceFill(['created_at' => now()->subDay()])->save();

        // A meeting order left over from yesterday
        $yesterdayMeeting = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'meeting',
            'delivery_time' => now()->subDay()->setTime(14, 0),
            'status' => 'approved',
            'approved_at' => now()->subDays(2),
        ]);

        // Kitchen display sees the overdue badge
        Livewire::actingAs($barista)
            ->test('barista.kitchen-display')
            ->assertSee(__('cbvh.banner_overdue'));

        // Yesterday's stale orders are auto-expired
        $this->assertEquals('rejected_by_barista', $yesterdayOrder->fresh()->status);
        $this->assertEquals('rejected_by_barista', $yesterdayMeeting->fresh()->status);
    }

    public function test_admin_can_create_staff_account_and_export_csv(): void
    {
        $hq = Branch::where('is_hq', true)->first();
        $admin = User::where('email', 'admin@company.com')->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $room = Room::where('branch_id', $hq->id)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        $order = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $order->items()->create([
            'drink_item_id' => $drink->id,
            'quantity' => 2,
            'selected_options' => ['sugar' => '50%'],
        ]);

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->set('newName', 'Bopha Meas')
            ->set('newEmployeeId', 'EMP-777')
            ->set('newPassword', 'secret123')
            ->set('newPassword_confirmation', 'secret123')
            ->set('newBranchId', $hq->id)
            ->set('newRole', 'employee')
            ->call('createStaffAccount')
            ->set('filterType', 'instant')
            ->call('exportCsv')
            ->assertFileDownloaded();

        $this->assertDatabaseHas('users', [
            'name' => 'Bopha Meas',
            'employee_id' => 'EMP-777',
            'branch_id' => $hq->id,
            'role' => 'employee',
        ]);

        // Verify the newly created staff member can log in using their Employee ID
        Livewire::test('auth.login-page')
            ->set('loginId', 'EMP-777')
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect('/');

        $this->assertAuthenticated();
        $this->assertSame('EMP-777', Auth::user()->employee_id);
    }

    public function test_admin_can_export_drink_totals_summary_and_drinks_are_grouped_by_base_name(): void
    {
        $hq = Branch::where('code', 'HQ')->first();
        $admin = User::where('role', 'admin')->first();
        $room = Room::where('branch_id', $hq->id)->first();

        $latte = DrinkItem::firstOrCreate([
            'branch_id' => $hq->id,
            'name' => 'Hot Latte',
        ], [
            'category' => 'coffee',
            'description' => 'Test Latte',
        ]);

        $greenTea = DrinkItem::firstOrCreate([
            'branch_id' => $hq->id,
            'name' => 'Iced Green Tea Latte',
        ], [
            'category' => 'tea',
            'description' => 'Test Matcha',
        ]);

        $order = DrinkOrder::create([
            'user_id' => $admin->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'completed',
            'people_count' => 3,
        ]);

        DrinkOrderItem::create([
            'drink_order_id' => $order->id,
            'drink_item_id' => $latte->id,
            'quantity' => 2,
            'selected_options' => ['sugar' => '50%', 'milk' => 'Oat Milk'],
        ]);

        DrinkOrderItem::create([
            'drink_order_id' => $order->id,
            'drink_item_id' => $greenTea->id,
            'quantity' => 1,
            'selected_options' => ['sugar' => '0%'],
        ]);

        $this->assertSame('Latte', CbvhLocale::toBaseDrinkName('Hot Latte'));
        $this->assertSame('Latte', CbvhLocale::toBaseDrinkName('Iced Latte'));
        $this->assertSame('Green Tea', CbvhLocale::toBaseDrinkName('Iced Green Tea Latte'));
        $this->assertSame('Americano', CbvhLocale::toBaseDrinkName('Iced Americano'));

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('exportDrinkTotalsCsv')
            ->assertFileDownloaded();

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('setPeriod', 'today')
            ->assertSet('filterPeriod', 'today')
            ->assertSet('dateFrom', today()->toDateString())
            ->assertSet('dateTo', today()->toDateString())
            ->call('setPeriod', 'this_month')
            ->assertSet('filterPeriod', 'this_month')
            ->assertSet('dateFrom', now()->startOfMonth()->toDateString())
            ->set('filterUserId', $admin->id)
            ->assertSeeText('#'.$order->id)
            ->set('searchQuery', 'NonExistentXYZ999')
            ->assertDontSeeText('#'.$order->id)
            ->set('searchQuery', '')
            ->set('perPage', 30)
            ->assertSet('perPage', 30);
    }

    public function test_telegram_webhook_does_not_auto_create_unverified_accounts(): void
    {
        $response = $this->postJson('/telegram/webhook', [
            'update_id' => 123456,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 999999],
                'from' => ['id' => 999999, 'first_name' => 'Stranger'],
                'text' => '/start',
            ],
        ]);
        $response->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('users', [
            'telegram_chat_id' => '999999',
        ]);
    }

    public function test_cloudflare_proxy_headers_generate_https_asset_urls(): void
    {
        $employee = User::where('email', 'employee@company.com')->first();

        $response = $this->actingAs($employee)->withHeaders([
            'Host' => 'demo-tunnel.trycloudflare.com',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'demo-tunnel.trycloudflare.com',
        ])->get('/');

        $response->assertStatus(200);
        $response->assertSee('https://demo-tunnel.trycloudflare.com/build/assets/', false);
        $response->assertSee('https://demo-tunnel.trycloudflare.com/livewire-', false);
        $response->assertDontSee('http://demo-tunnel.trycloudflare.com/', false);
        $response->assertDontSee('http://localhost:8000/', false);
    }

    public function test_application_timezone_is_cambodia_phnom_penh(): void
    {
        $this->assertSame('Asia/Phnom_Penh', config('app.timezone'));
        $this->assertSame('Asia/Phnom_Penh', date_default_timezone_get());
        $this->assertSame('+07:00', now()->format('P'));
    }

    public function test_meeting_can_be_booked_anytime_for_working_hours_arrival_including_lunch(): void
    {
        $hq = Branch::where('is_hq', true)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        // User books at 10:30 PM (outside working hours)
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 22, 30, 0));

        // Arrival time outside working hours (07:45) must fail
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'meeting')
            ->set('meetingDate', '2026-11-14')
            ->set('meetingClock', '07:45')
            ->call('selectDrink', $drink->id)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'error');

        // Arrival time after 5:00 PM (17:15) must fail
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'meeting')
            ->set('meetingDate', '2026-11-14')
            ->set('meetingClock', '17:15')
            ->call('selectDrink', $drink->id)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'error');

        $this->assertDatabaseCount('drink_orders', 0);

        // Arrival time during lunch (12:30) booked at 10:30 PM must succeed
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'meeting')
            ->set('meetingDate', '2026-11-14')
            ->set('meetingClock', '12:30')
            ->call('selectDrink', $drink->id)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseCount('drink_orders', 1);
    }

    public function test_barista_defaults_to_khmer_and_translates_drinks_options_rooms_and_times(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 13, 30, 0));

        $hq = Branch::where('is_hq', true)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $barista = User::where('email', 'barista@company.com')->first();
        $showroom = Room::where('branch_id', $hq->id)->where('name', 'Showroom')->first();
        $coffee = DrinkItem::where('branch_id', $hq->id)->where('name', 'Ice Milk Coffee')->first();

        $order = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $showroom->id,
            'order_type' => 'instant',
            'status' => 'approved',
            'people_count' => 2,
            'estimated_wait_minutes' => 4,
            'delivery_time' => now()->addMinutes(4),
        ]);
        $order->items()->create([
            'drink_item_id' => $coffee->id,
            'quantity' => 2,
            'selected_options' => [
                'sugar' => 'Normal Sugar',
                'ice' => 'Less Ice',
            ],
        ]);

        $response = $this->actingAs($barista)->get('/barista');

        $response->assertStatus(200)
            ->assertSee('<title>អេក្រង់កន្លែងឆុងភេសជ្ជៈ</title>', false)
            ->assertSee('អេក្រង់កន្លែងឆុងភេសជ្ជៈ')
            ->assertSee('ស៊ីប៊ីវីអេច CBVH')
            ->assertSee('Showroom')
            ->assertSee('ជាន់ផ្ទាល់ដី')
            ->assertSee('កាហ្វេទឹកដោះគោ')
            ->assertSee('ធម្មតា')
            ->assertSee('តិច')
            ->assertSee('រសៀល')
            ->assertSee('ឆាប់បំផុត')
            ->assertSee('កម្ម៉ង់ម៉ោង')
            ->assertSee('១. ទទួលការកម្ម៉ង់')
            ->assertDontSee('Barista Kitchen Display')
            ->assertDontSee('Barista Display')
            ->assertDontSee('Ice Milk Coffee')
            ->assertDontSee('Normal Sugar')
            ->assertDontSee('Less Ice')
            ->assertDontSee('Out of Stock')
            ->assertDontSee('អស់ស្តុក')
            ->assertDontSee('បិទបញ្ចប់ដោយផ្ទាល់')
            ->assertDontSee($employee->name);
    }

    public function test_barista_cards_stay_in_fixed_order_through_status_changes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        $hq = Branch::where('is_hq', true)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $barista = User::where('email', 'barista@company.com')->first();
        $room = Room::where('branch_id', $hq->id)->first();

        $first = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'approved',
            'delivery_time' => now()->addMinutes(2),
        ]);

        $second = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'approved',
            'delivery_time' => now()->addMinutes(4),
        ]);

        // Even after #1 becomes preparing then in_delivery, #1 must stay before #2
        Livewire::actingAs($barista)
            ->test('barista.kitchen-display')
            ->call('acceptOrder', $first->id)
            ->assertSeeInOrder(["#{$first->id}", "#{$second->id}"])
            ->call('markInDelivery', $first->id)
            ->assertSeeInOrder(["#{$first->id}", "#{$second->id}"]);
    }

    public function test_hq_barista_sees_bak_touk_branch_meeting_orders_with_send_to_branch_label(): void
    {
        $barista = User::where('email', 'barista@company.com')->first();
        $branchUser = User::where('email', 'branch.staff@company.com')->first();
        $bakTouk = Branch::where('is_hq', false)->first();
        $branchRoom = Room::where('branch_id', $bakTouk->id)->first();
        $drink = DrinkItem::where('branch_id', $bakTouk->id)->first();

        $order = DrinkOrder::create([
            'user_id' => $branchUser->id,
            'branch_id' => $bakTouk->id,
            'room_id' => $branchRoom->id,
            'order_type' => 'meeting',
            'delivery_time' => now()->addDay()->setTime(10, 0),
            'status' => 'approved',
        ]);
        $order->items()->create([
            'drink_item_id' => $drink->id,
            'quantity' => 4,
            'selected_options' => ['sugar' => '50%'],
        ]);

        $this->actingAs($barista)
            ->get('/barista')
            ->assertStatus(200)
            ->assertSee('ផ្ញើទៅសាខា')
            ->assertSee('ជាង បាក់ វណ្ណ ហុង (បាក់ទូក)')
            ->assertSee('បាក់ទូក')
            ->assertSee('កាលវិភាគថ្ងៃខាងមុខ')
            ->assertDontSee('១. ទទួលការកម្ម៉ង់');

        // Future-day meeting orders stay locked so barista cannot brew tomorrow's drinks early
        Livewire::actingAs($barista)
            ->test('barista.kitchen-display')
            ->call('acceptOrder', $order->id);

        $this->assertDatabaseHas('drink_orders', [
            'id' => $order->id,
            'status' => 'approved',
        ]);
    }

    public function test_quick_drink_supports_later_today_preorder_and_auto_sets_people_to_cups(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 13, 0, 0));

        $hq = Branch::where('is_hq', true)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        // Past time today must fail
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'instant')
            ->call('setQuickTiming', 'later_today')
            ->set('quickClock', '09:00')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 2)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'error');

        $this->assertDatabaseCount('drink_orders', 0);

        // Later time today within working hours succeeds
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'instant')
            ->call('setQuickTiming', 'later_today')
            ->set('quickClock', '15:30')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 2)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $saved = DrinkOrder::first();
        $this->assertSame('instant', $saved->order_type);
        $this->assertSame(2, $saved->people_count);
        $this->assertNull($saved->estimated_wait_minutes);
        $this->assertSame('2026-11-12 15:30:00', $saved->delivery_time->format('Y-m-d H:i:s'));

        // Barista screen shows Later Today label and single chosen time (no confusing second clock)
        $barista = User::where('email', 'barista@company.com')->first();
        $this->actingAs($barista)
            ->get('/barista')
            ->assertStatus(200)
            ->assertSee('ម៉ោងក្រោយថ្ងៃនេះ')
            ->assertSee('03:30 រសៀល')
            ->assertDontSee('កម្ម៉ង់ម៉ោង:');
    }

    public function test_hq_user_can_order_quick_drink_for_bak_touk_branch_while_branch_user_cannot(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        $bakTouk = Branch::where('is_hq', false)->first();
        $hqUser = User::where('email', 'employee@company.com')->first();
        $branchUser = User::where('email', 'branch.staff@company.com')->first();
        $bakToukDrink = DrinkItem::where('branch_id', $bakTouk->id)->first();
        $bakToukRoom = Room::where('branch_id', $bakTouk->id)->first();

        // HQ user selects Bak Touk branch and orders a Quick Drink for Bak Touk
        Livewire::actingAs($hqUser)
            ->test('mini-app.booking-app')
            ->set('selectedBranchId', $bakTouk->id)
            ->set('selectedRoomId', $bakToukRoom->id)
            ->call('setOrderType', 'instant')
            ->assertSet('orderType', 'instant')
            ->call('selectDrink', $bakToukDrink->id)
            ->set('drinkQuantity', 2)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseHas('drink_orders', [
            'user_id' => $hqUser->id,
            'branch_id' => $bakTouk->id,
            'room_id' => $bakToukRoom->id,
            'order_type' => 'instant',
            'people_count' => 2,
        ]);

        // Branch user still cannot order Quick Drink
        Livewire::actingAs($branchUser)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'instant')
            ->assertSet('flashType', 'error')
            ->assertSet('orderType', 'meeting');
    }

    public function test_quick_drink_daily_ordering_is_unlimited_per_user(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        $hq = Branch::where('is_hq', true)->first();
        $hqUser = User::where('email', 'employee@company.com')->first();
        $admin = User::where('email', 'admin@company.com')->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        // 1. User orders 6 Quick Drink cups -> succeeds
        Livewire::actingAs($hqUser)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'instant')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 6)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        // 2. User orders 5 more Quick Drink cups (6 + 5 = 11 cups) -> succeeds (unlimited)
        Livewire::actingAs($hqUser)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'instant')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 5)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $this->assertSame(11, DrinkOrder::quickCupsUsedToday($hqUser->id));

        // 3. User can also order 15 Meeting Drink cups
        Livewire::actingAs($hqUser)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'meeting')
            ->set('meetingDate', '2026-11-14')
            ->set('meetingClock', '10:00')
            ->set('peopleCount', 15)
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 15)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        // 4. A different user has their own separate count
        $this->assertSame(0, DrinkOrder::quickCupsUsedToday($admin->id));
    }

    public function test_my_orders_pins_active_orders_and_paginates_older_history(): void
    {
        $hq = Branch::where('is_hq', true)->first();
        $hqUser = User::where('email', 'employee@company.com')->first();
        $room = Room::where('branch_id', $hq->id)->first();

        $activeOrder = DrinkOrder::create([
            'user_id' => $hqUser->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'in_delivery',
            'in_delivery_at' => now(),
        ]);

        // Create 12 completed orders after the active one
        $pastOrders = [];
        for ($i = 0; $i < 12; $i++) {
            $pastOrders[] = DrinkOrder::create([
                'user_id' => $hqUser->id,
                'branch_id' => $hq->id,
                'room_id' => $room->id,
                'order_type' => 'instant',
                'status' => 'completed',
                'completed_at' => now()->addMinutes($i),
            ]);
        }

        $oldestPast = $pastOrders[0];
        $newestPast = $pastOrders[11];

        Livewire::actingAs($hqUser)
            ->test('mini-app.booking-app')
            ->set('activeTab', 'history')
            ->assertSeeInOrder(["#{$activeOrder->id}", "#{$newestPast->id}"])
            ->assertDontSee("#{$oldestPast->id} •")
            ->assertSee(__('cbvh.show_more_orders'))
            ->call('loadMoreHistory')
            ->assertSee("#{$oldestPast->id} •")
            ->assertDontSee(__('cbvh.show_more_orders'));
    }

    public function test_all_logout_buttons_include_confirmation_modal(): void
    {
        $employee = User::where('email', 'employee@company.com')->first();
        $barista = User::where('email', 'barista@company.com')->first();
        $admin = User::where('email', 'admin@company.com')->first();

        $this->actingAs($employee)
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Are you sure you want to sign out?');

        $this->actingAs($barista)
            ->get('/barista')
            ->assertStatus(200)
            ->assertSee('តើអ្នកប្រាកដថាចង់ចាកចេញមែនទេ?');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertStatus(200)
            ->assertSee('Are you sure you want to sign out?');
    }

    public function test_admin_staff_directory_supports_search_and_pagination_with_case_insensitive_employee_id(): void
    {
        $admin = User::where('role', 'admin')->first();
        $hq = Branch::where('code', 'HQ')->first();

        // Create user with employee id A100888
        User::create([
            'name' => 'John Doe',
            'employee_id' => 'A100888',
            'email' => 'a100888@cbvh.internal',
            'password' => bcrypt('password123'),
            'role' => 'employee',
            'branch_id' => $hq->id,
        ]);

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->set('activeSection', 'team')
            ->assertSee('A100888')
            ->set('staffSearch', 'A100888')
            ->assertSee('John Doe')
            ->set('staffSearch', 'non_existing_999')
            ->assertSee('No staff accounts match your search.');

        // Test login with lowercase a100888
        Livewire::test('auth.login-page')
            ->set('loginId', 'a100888')
            ->set('password', 'password123')
            ->call('login')
            ->assertRedirect('/');

        $this->assertAuthenticated();
        $this->assertSame('A100888', Auth::user()->employee_id);
    }

    public function test_admin_can_reset_staff_password_and_staff_can_login_with_new_password(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employee = User::where('email', 'employee@company.com')->first();

        // 1. Admin opens reset password modal for employee and saves new password
        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->set('activeSection', 'team')
            ->call('openResetPasswordModal', $employee->id)
            ->assertSet('showResetPasswordModal', true)
            ->assertSet('resettingUserId', $employee->id)
            ->set('newStaffResetPassword', 'NewSecret999!')
            ->call('saveNewPassword')
            ->assertSet('showResetPasswordModal', false)
            ->assertSet('flashType', 'success');

        // 2. Log out admin before testing employee login
        Auth::logout();

        // Old password ('password') should now fail
        Livewire::test('auth.login-page')
            ->set('loginId', $employee->employee_id ?: $employee->email)
            ->set('password', 'password')
            ->call('login')
            ->assertSee(__('cbvh.invalid_credentials'));

        $this->assertGuest();

        // 3. New password ('NewSecret999!') should succeed
        Livewire::test('auth.login-page')
            ->set('loginId', $employee->employee_id ?: $employee->email)
            ->set('password', 'NewSecret999!')
            ->call('login')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($employee->fresh());
    }

    public function test_single_device_telegram_lock_blocks_hijacking_and_notifies_owner(): void
    {
        $botToken = config('services.telegram.bot_token') ?: 'test-token-123';
        config(['services.telegram.bot_token' => $botToken]);

        $hq = Branch::where('code', 'HQ')->first();
        $employee = User::create([
            'name' => 'Genuine Staff',
            'employee_id' => 'A100888',
            'email' => 'genuine@cbvh.internal',
            'password' => bcrypt('correct-password'),
            'role' => 'employee',
            'branch_id' => $hq->id,
            'telegram_chat_id' => '111111',
            'telegram_username' => 'genuine_user',
        ]);

        // Generate attacker's signed initData with Telegram ID 999999
        $attackerTgJson = json_encode(['id' => 999999, 'first_name' => 'Attacker', 'username' => 'attacker_bot']);
        $fields = ['auth_date' => (string) time(), 'user' => $attackerTgJson];
        ksort($fields);
        $hash = hash_hmac('sha256', "auth_date={$fields['auth_date']}\nuser={$fields['user']}", hash_hmac('sha256', $botToken, 'WebAppData', true));
        $attackerInitData = http_build_query($fields + ['hash' => $hash]);

        // Attacker attempts login with genuine employee's ID and password from attacker's Telegram device
        Livewire::test('auth.login-page')
            ->set('telegramInitData', $attackerInitData)
            ->set('loginId', 'A100888')
            ->set('password', 'correct-password')
            ->call('login')
            ->assertSee(__('cbvh.device_locked_error'))
            ->assertNoRedirect();

        // Login is rejected and user remains a guest
        $this->assertGuest();

        // Database record remains intact and bound to genuine user's device
        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'telegram_chat_id' => '111111',
            'telegram_username' => 'genuine_user',
        ]);

        // Security notification was dispatched to genuine user's Telegram chat ID
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/sendMessage')
                && (string) $request['chat_id'] === '111111'
                && str_contains($request['text'], 'CBVH Security Alert')
                && str_contains($request['text'], 'attacker_bot');
        });
    }

    public function test_kitchen_display_dispatches_distinct_audio_alert_events_for_quick_and_meeting_orders(): void
    {
        $barista = User::where('role', 'barista')->first();
        $employee = User::where('role', 'employee')->first();
        $hq = Branch::where('code', 'HQ')->first();
        $room = $hq->rooms()->first();

        // 1. Mount kitchen display
        $component = Livewire::actingAs($barista)->test('barista.kitchen-display');

        // 2. Create a new Quick Order
        $quickOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'approved',
        ]);

        $component->call('$refresh')
            ->assertDispatched('new-barista-order', orderType: 'quick');

        // 3. Create a new Meeting Order
        $meetingOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'meeting',
            'status' => 'approved',
            'delivery_time' => now()->addHours(2),
        ]);

        $component->call('$refresh')
            ->assertDispatched('new-barista-order', orderType: 'meeting');
    }

    public function test_quick_daily_cup_limit_is_10_for_staff_and_50_for_admin(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employee = User::where('role', 'employee')->first();
        $hq = Branch::where('code', 'HQ')->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        // 1. Check helper limits
        $this->assertSame(10, DrinkOrder::quickDailyLimitForUser($employee));
        $this->assertSame(50, DrinkOrder::quickDailyLimitForUser($admin));

        // 2. Employee Mini App displays limit 10
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->assertSet('orderType', 'instant')
            ->assertViewHas('quickDailyLimit', 10);

        // 3. Admin Mini App displays limit 50
        Livewire::actingAs($admin)
            ->test('mini-app.booking-app')
            ->assertSet('orderType', 'instant')
            ->assertViewHas('quickDailyLimit', 50);

        // 4. Employee adding 11 cups in quick order succeeds (unlimited daily ordering)
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 11)
            ->call('addToCart')
            ->assertSet('flashType', 'success');

        // 5. Admin can successfully add 15 cups in quick order
        Livewire::actingAs($admin)
            ->test('mini-app.booking-app')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 15)
            ->call('addToCart')
            ->assertSet('flashType', 'success')
            ->assertCount('drinkCart', 1);
    }

    public function test_barista_marking_in_delivery_sends_mini_app_link_to_user_telegram(): void
    {
        $barista = User::where('role', 'barista')->first();
        $employee = User::where('role', 'employee')->first();
        $employee->update(['telegram_chat_id' => '12345678']);

        $hq = Branch::where('code', 'HQ')->first();
        $room = $hq->rooms()->first();

        $order = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'preparing',
        ]);

        Livewire::actingAs($barista)
            ->test('barista.kitchen-display')
            ->call('markInDelivery', $order->id);

        $this->assertSame('in_delivery', $order->fresh()->status);

        Http::assertSent(function (Request $request) use ($order) {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }
            if ((string) $request['chat_id'] !== '12345678') {
                return false;
            }
            $replyMarkup = json_decode($request['reply_markup'] ?? '{}', true);
            $button = $replyMarkup['inline_keyboard'][0][0] ?? [];

            return str_contains($request['text'], "Order #{$order->id} is OUT FOR DELIVERY")
                && str_contains($button['text'], 'Open Mini App')
                && ! empty($button['web_app']['url']);
        });
    }

    public function test_meeting_drink_automatically_sets_people_count_equal_to_total_cups_without_people_input(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        $employee = User::where('role', 'employee')->first();
        $hq = Branch::where('code', 'HQ')->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        $file = UploadedFile::fake()->create('agenda.pdf', 100, 'application/pdf');

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'meeting')
            ->assertDontSeeHtml('wire:model="peopleCount"')
            ->set('meetingDate', '2026-11-14')
            ->set('meetingClock', '14:00')
            ->set('attachment', $file)
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 5)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseHas('drink_orders', [
            'order_type' => 'meeting',
            'people_count' => 5,
        ]);
    }

    public function test_admin_can_create_bilingual_drink_with_dynamic_custom_layers(): void
    {
        $admin = User::where('role', 'admin')->first();

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->set('activeSection', 'drinks')
            ->call('openCreateDrinkModal')
            ->set('drinkNameEn', 'Brown Sugar Milk Tea')
            ->set('drinkNameKm', 'តែទឹកដោះគោស្ករត្នោត')
            ->set('drinkCategory', 'tea')
            ->set('drinkDescriptionEn', 'Creamy rich milk tea')
            ->set('drinkDescriptionKm', 'តែទឹកដោះគោរសជាតិឈ្ងុយឆ្ងាញ់')
            ->set('drinkLayers', [
                [
                    'id' => 'shake_style',
                    'name_en' => 'Preparation Style',
                    'name_km' => 'របៀបក្រឡុក',
                    'options' => [
                        ['en' => 'Shaken', 'km' => 'ក្រឡុក'],
                        ['en' => 'Blended', 'km' => 'កិន'],
                    ],
                ],
                [
                    'id' => 'sweetness',
                    'name_en' => 'Sweetness',
                    'name_km' => 'កម្រិតជាតិផ្អែម',
                    'options' => [
                        ['en' => '30%', 'km' => '៣០%'],
                        ['en' => '50%', 'km' => '៥០%'],
                        ['en' => '100%', 'km' => '១០០%'],
                    ],
                ],
            ])
            ->call('saveDrink')
            ->assertSet('showDrinkModal', false);

        $this->assertDatabaseHas('drink_items', [
            'name' => 'Brown Sugar Milk Tea',
            'name_km' => 'តែទឹកដោះគោស្ករត្នោត',
            'category' => 'tea',
            'is_available' => true,
        ]);

        $created = DrinkItem::where('name', 'Brown Sugar Milk Tea')->first();
        $this->assertNotNull($created);
        $this->assertCount(2, $created->normalized_layers);
        $this->assertEquals('Preparation Style', $created->normalized_layers[0]['name_en']);
        $this->assertEquals('របៀបក្រឡុក', $created->normalized_layers[0]['name_km']);
    }

    public function test_admin_unchecking_branch_removes_drink_from_that_branch(): void
    {
        $admin = User::where('role', 'admin')->first();
        $hq = Branch::where('code', 'HQ')->first();
        $btk = Branch::where('code', 'BTK')->first();

        // Initially exists at both branches
        $this->assertDatabaseHas('drink_items', ['name' => 'Ice Milk Coffee', 'branch_id' => $hq->id]);
        $this->assertDatabaseHas('drink_items', ['name' => 'Ice Milk Coffee', 'branch_id' => $btk->id]);

        $drinkHq = DrinkItem::where('name', 'Ice Milk Coffee')->where('branch_id', $hq->id)->first();

        // Admin edits and unchecks BTK (target branches: [HQ only])
        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('openEditDrinkModal', $drinkHq->id)
            ->set('drinkTargetBranches', [$hq->id])
            ->call('saveDrink')
            ->assertSet('showDrinkModal', false);

        // Still exists at HQ
        $this->assertDatabaseHas('drink_items', ['name' => 'Ice Milk Coffee', 'branch_id' => $hq->id]);
        // REMOVED from BTK
        $this->assertDatabaseMissing('drink_items', ['name' => 'Ice Milk Coffee', 'branch_id' => $btk->id]);
    }

    public function test_admin_can_toggle_drink_availability_and_staff_cannot_order_out_of_stock_item(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employee = User::where('role', 'employee')->first();
        $drink = DrinkItem::where('name', 'Ice Milk Coffee')->first();

        $this->assertTrue((bool) $drink->is_available);

        // Admin toggles to Out of Stock
        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('toggleDrinkAvailability', $drink->id);

        $drink->refresh();
        $this->assertFalse((bool) $drink->is_available);

        // Staff tries to select out-of-stock drink in Mini App
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('selectDrink', $drink->id)
            ->assertSet('selectedDrinkId', null);

        // Admin toggles back to In Stock
        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('toggleDrinkAvailability', $drink->id);

        $drink->refresh();
        $this->assertTrue((bool) $drink->is_available);

        // Staff can now select it
        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('selectDrink', $drink->id)
            ->assertSet('selectedDrinkId', $drink->id);
    }

    public function test_staff_can_customize_and_order_drink_with_dynamic_layers(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        $employee = User::where('role', 'employee')->first();
        $hq = Branch::where('code', 'HQ')->first();

        $customDrink = DrinkItem::create([
            'branch_id' => $hq->id,
            'name' => 'Signature Milk Tea',
            'name_km' => 'តែទឹកដោះគោពិសេស',
            'category' => 'tea',
            'is_available' => true,
            'options_config' => [
                'layers' => [
                    [
                        'id' => 'style',
                        'name_en' => 'Preparation Style',
                        'name_km' => 'របៀបឆុង',
                        'options' => [
                            ['en' => 'Shaken', 'km' => 'ក្រឡុក (Shake)'],
                            ['en' => 'Stirred', 'km' => 'កូរ (Stirred)'],
                        ],
                    ],
                    [
                        'id' => 'sweetness',
                        'name_en' => 'Sweetness',
                        'name_km' => 'កម្រិតជាតិផ្អែម',
                        'options' => [
                            ['en' => '25% Sweet', 'km' => 'ផ្អែមតិច ២៥%'],
                            ['en' => '50% Half Sweet', 'km' => 'ផ្អែមល្មម ៥០%'],
                        ],
                    ],
                ],
            ],
        ]);

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('selectDrink', $customDrink->id)
            ->assertSet('selectedDrinkId', $customDrink->id)
            ->set('selectedCustomLayers.0', 'Stirred')
            ->set('selectedCustomLayers.1', '25% Sweet')
            ->set('drinkQuantity', 2)
            ->call('addToCart')
            ->assertCount('drinkCart', 1)
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseHas('drink_order_items', [
            'drink_item_id' => $customDrink->id,
            'quantity' => 2,
        ]);

        $orderItem = DrinkOrderItem::where('drink_item_id', $customDrink->id)->latest('id')->first();
        $this->assertNotNull($orderItem);
        $options = $orderItem->selected_options;
        $this->assertArrayHasKey('Preparation Style', $options);
        $this->assertEquals('Stirred', $options['Preparation Style']['en']);
        $this->assertEquals('កូរ (Stirred)', $options['Preparation Style']['km']);
        $this->assertArrayHasKey('Sweetness', $options);
        $this->assertEquals('25% Sweet', $options['Sweetness']['en']);
        $this->assertEquals('ផ្អែមតិច ២៥%', $options['Sweetness']['km']);
    }

    public function test_user_cannot_submit_extend_order_for_another_users_order(): void
    {
        $victim = User::factory()->create(['role' => 'employee']);
        $attacker = User::factory()->create(['role' => 'employee']);
        $branch = Branch::first();
        $room = Room::where('branch_id', $branch->id)->first();
        $drink = DrinkItem::where('branch_id', $branch->id)->first();

        // Victim has an in_delivery order
        $victimOrder = DrinkOrder::create([
            'user_id' => $victim->id,
            'branch_id' => $branch->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'in_delivery',
            'in_delivery_at' => now(),
        ]);
        DrinkOrderItem::create([
            'drink_order_id' => $victimOrder->id,
            'drink_item_id' => $drink->id,
            'quantity' => 2,
        ]);

        // Attacker attempts to forge extend against victim's order
        Livewire::actingAs($attacker)
            ->test('mini-app.booking-app')
            ->set('extendingOrderId', $victimOrder->id)
            ->set('extendReason', 'spilled')
            ->call('selectDrink', $drink->id)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'error');

        // Verify victim order was NOT completed by attacker
        $this->assertEquals('in_delivery', $victimOrder->fresh()->status);
    }

    public function test_quick_cups_used_today_includes_non_spill_extend_orders(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $branch = Branch::first();
        $room = Room::where('branch_id', $branch->id)->first();
        $drink = DrinkItem::where('branch_id', $branch->id)->first();

        // 1. Instant order of 3 cups
        $order1 = DrinkOrder::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'completed',
        ]);
        DrinkOrderItem::create([
            'drink_order_id' => $order1->id,
            'drink_item_id' => $drink->id,
            'quantity' => 3,
        ]);

        // 2. Extend order with reason = 'extra_guests' of 2 cups
        $order2 = DrinkOrder::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'room_id' => $room->id,
            'order_type' => 'extend',
            'extend_reason' => 'extra_guests',
            'parent_order_id' => $order1->id,
            'status' => 'completed',
        ]);
        DrinkOrderItem::create([
            'drink_order_id' => $order2->id,
            'drink_item_id' => $drink->id,
            'quantity' => 2,
        ]);

        // 3. Extend order with reason = 'spilled' of 1 cup (spill does NOT count)
        $order3 = DrinkOrder::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'room_id' => $room->id,
            'order_type' => 'extend',
            'extend_reason' => 'spilled',
            'parent_order_id' => $order1->id,
            'status' => 'completed',
        ]);
        DrinkOrderItem::create([
            'drink_order_id' => $order3->id,
            'drink_item_id' => $drink->id,
            'quantity' => 1,
        ]);

        // Total should be 3 (instant) + 2 (extra_guests extend) = 5. (Spilled is excluded)
        $this->assertEquals(5, DrinkOrder::quickCupsUsedToday($user->id));
    }

    public function test_admin_cannot_demote_themselves_from_admin_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('updateUserRole', $admin->id, 'employee')
            ->assertSet('flashType', 'error');

        $this->assertEquals('admin', $admin->fresh()->role);
    }

    public function test_admin_can_edit_staff_name_and_employee_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['name' => 'Old Name', 'employee_id' => 'OLD123']);
        $branch = Branch::first();

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('openEditStaffModal', $staff->id)
            ->assertSet('editingStaffId', $staff->id)
            ->assertSet('editingStaffName', 'Old Name')
            ->assertSet('editingStaffEmployeeId', 'OLD123')
            ->set('editingStaffName', 'New Correct Name')
            ->set('editingStaffEmployeeId', 'EMP999')
            ->call('saveEditedStaff')
            ->assertSet('flashType', 'success');

        $staff->refresh();
        $this->assertEquals('New Correct Name', $staff->name);
        $this->assertEquals('EMP999', $staff->employee_id);
    }

    public function test_cannot_submit_drink_order_with_mismatched_branch_and_room(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $branch1 = Branch::first();
        $branch2 = Branch::skip(1)->first() ?? Branch::factory()->create();
        $roomInBranch2 = Room::where('branch_id', $branch2->id)->first() ?? Room::factory()->create(['branch_id' => $branch2->id]);
        $drink = DrinkItem::where('branch_id', $branch1->id)->first();

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->set('selectedBranchId', $branch1->id)
            ->set('selectedRoomId', $roomInBranch2->id) // Mismatched room!
            ->call('selectDrink', $drink->id)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', 'Selected room does not belong to the selected branch.');
    }

    public function test_staff_creation_fails_if_password_confirmation_does_not_match(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $hq = Branch::first();

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->set('newName', 'Mismatched User')
            ->set('newEmployeeId', 'EMP-MISMATCH')
            ->set('newPassword', 'password123')
            ->set('newPassword_confirmation', 'different_password')
            ->set('newBranchId', $hq->id)
            ->set('newRole', 'employee')
            ->call('createStaffAccount')
            ->assertHasErrors(['newPassword']);

        $this->assertDatabaseMissing('users', ['employee_id' => 'EMP-MISMATCH']);
    }

    public function test_drink_quantity_can_be_typed_directly_in_cart(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $hq = Branch::where('is_hq', true)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 7) // Directly set/typed 7
            ->call('addToCart')
            ->assertCount('drinkCart', 1)
            ->assertSet('drinkCart.0.quantity', 7);
    }

    public function test_admin_can_manage_recurring_schedule_and_calendar_dates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $hq = Branch::where('is_hq', true)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        $component = Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->set('activeSection', 'recurring')
            ->set('scheduleTitle', 'Executive Floor Schedule Order')
            ->set('scheduleBranchId', $hq->id)
            ->set('scheduleLocationDetail', '3rd Floor VIP Room')
            ->set('scheduleDeliveryTime', '09:00')
            ->call('saveRecurringSchedule')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseHas('recurring_schedules', [
            'title' => 'Executive Floor Schedule Order',
            'location_detail' => '3rd Floor VIP Room',
            'delivery_time' => '09:00',
        ]);

        $schedule = RecurringSchedule::first();

        // Test prefilling working days
        $component->call('prefillScheduleWorkingDays', false); // Mon-Fri
        $this->assertTrue($schedule->dates()->count() > 15);

        // Test updating drink package and saving
        $component->set('scheduleItems', [
            [
                'id' => null,
                'drink_item_id' => $drink->id,
                'name' => $drink->name,
                'quantity' => 10,
                'sugar_level' => 'Less Sugar',
                'ice_level' => 'Normal Ice',
            ],
        ])->call('saveRecurringSchedule');

        $this->assertDatabaseHas('recurring_schedule_items', [
            'recurring_schedule_id' => $schedule->id,
            'drink_item_id' => $drink->id,
            'quantity' => 10,
            'sugar_level' => 'Less Sugar',
        ]);

        // Verify re-mounting component retains the saved drink package
        $newComponent = Livewire::actingAs($admin)->test('admin.⚡approval-portal');
        $items = $newComponent->get('scheduleItems');
        $this->assertCount(1, $items);
        $this->assertEquals($drink->id, $items[0]['drink_item_id']);
        $this->assertEquals(10, $items[0]['quantity']);
    }

    public function test_recurring_order_auto_dispatch_creates_barista_ticket_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barista = User::factory()->create(['role' => 'barista']);
        $hq = Branch::where('is_hq', true)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        $schedule = RecurringSchedule::create([
            'title' => 'Daily HQ Morning Coffee',
            'branch_id' => $hq->id,
            'location_detail' => 'Floor 2 Reception',
            'delivery_time' => '08:30',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        RecurringScheduleItem::create([
            'recurring_schedule_id' => $schedule->id,
            'drink_item_id' => $drink->id,
            'quantity' => 10,
            'sugar_level' => 'Normal Sugar',
            'ice_level' => 'Normal Ice',
        ]);

        // Schedule for today
        RecurringScheduleDate::create([
            'recurring_schedule_id' => $schedule->id,
            'delivery_date' => today()->toDateString(),
            'status' => 'scheduled',
        ]);

        // Dispatch
        $service = app(RecurringOrderService::class);
        $orders = $service->dispatchDueOrders(today());

        $this->assertCount(1, $orders);
        $order = $orders[0];

        $this->assertEquals('recurring', $order->order_type);
        $this->assertEquals('approved', $order->status);
        $this->assertEquals(10, $order->people_count);
        $this->assertStringContainsString('Floor 2 Reception', $order->notes);

        $this->assertDatabaseHas('drink_order_items', [
            'drink_order_id' => $order->id,
            'drink_item_id' => $drink->id,
            'quantity' => 10,
        ]);

        // Idempotency: Running dispatch again should NOT create another order for today
        $repeatOrders = $service->dispatchDueOrders(today());
        $this->assertCount(0, $repeatOrders);
        $this->assertEquals(1, DrinkOrder::where('order_type', 'recurring')->count());

        // Barista Kitchen Display sees the order under instant/quick service orders
        $baristaComponent = Livewire::actingAs($barista)
            ->test('barista.kitchen-display')
            ->assertSee('Floor 2 Reception')
            ->assertSee('08:30 AM')
            ->assertSee('10')
            ->assertSee('Schedule Order');

        // Test Barista workflow: accept -> complete
        $baristaComponent->call('acceptOrder', $order->id);
        $this->assertEquals('preparing', $order->fresh()->status);

        $baristaComponent->call('completeOrder', $order->id);
        $this->assertEquals('completed', $order->fresh()->status);
    }

    public function test_admin_can_stage_calendar_dates_and_save_with_confirmation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $hq = Branch::where('is_hq', true)->first();

        $schedule = RecurringSchedule::firstOrCreate([
            'title' => 'Daily Schedule Order',
        ], [
            'branch_id' => $hq->id,
            'delivery_time' => '08:30',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $testDate = sprintf('%04d-%02d-15', now()->year, now()->month);

        $component = Livewire::actingAs($admin)
            ->test('admin.⚡approval-portal');

        // Toggle date in staging
        $component->call('toggleScheduleDate', $testDate)
            ->assertSet('calendarDirty', true);

        // Save calendar dates
        $component->call('saveScheduleCalendarDates')
            ->assertSet('calendarDirty', false);

        // Date should now be in DB
        $this->assertDatabaseHas('recurring_schedule_dates', [
            'recurring_schedule_id' => $schedule->id,
            'delivery_date' => $testDate,
            'status' => 'scheduled',
        ]);

        // Toggle off and discard
        $component->call('toggleScheduleDate', $testDate)
            ->assertSet('calendarDirty', true)
            ->call('discardCalendarChanges')
            ->assertSet('calendarDirty', false);

        // Date should still be in DB because changes were discarded
        $this->assertDatabaseHas('recurring_schedule_dates', [
            'recurring_schedule_id' => $schedule->id,
            'delivery_date' => $testDate,
            'status' => 'scheduled',
        ]);
    }

    public function test_telegram_username_collision_cannot_hijack_bound_account(): void
    {
        $employee = User::where('email', 'employee@company.com')->first();
        $employee->update([
            'telegram_chat_id' => '11111111',
            'telegram_username' => 'shared_handle',
        ]);

        // Attacker shares the same @username but has a different chat ID
        $resolved = app(TelegramService::class)->findLinkedTelegramUser('99999999', 'shared_handle');

        $this->assertNull($resolved);
        $this->assertEquals('11111111', $employee->fresh()->telegram_chat_id);
    }

    public function test_telegram_webhook_enforces_role_status_guards_and_optional_secret(): void
    {
        $hq = Branch::where('is_hq', true)->first();
        $room = Room::where('branch_id', $hq->id)->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $barista = User::where('email', 'barista@company.com')->first();

        $employee->update(['telegram_chat_id' => '100001']);
        $barista->update(['telegram_chat_id' => '200002']);

        $order = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'approved',
        ]);

        // 1. Secret token enforcement when configured
        config(['services.telegram.webhook_secret' => 'my-secret-xyz']);
        $this->postJson('/telegram/webhook', ['update_id' => 1])->assertStatus(403);

        // 2. Regular employee cannot trigger barista_accept_ even with valid secret
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-xyz'])
            ->postJson('/telegram/webhook', [
                'callback_query' => [
                    'id' => 'cb1',
                    'from' => ['id' => 100001],
                    'message' => ['chat' => ['id' => 100001]],
                    'data' => "barista_accept_{$order->id}",
                ],
            ])->assertStatus(200);
        $this->assertEquals('approved', $order->fresh()->status);

        // 3. Barista CAN accept and mark ready
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-xyz'])
            ->postJson('/telegram/webhook', [
                'callback_query' => [
                    'id' => 'cb2',
                    'from' => ['id' => 200002],
                    'message' => ['chat' => ['id' => 200002]],
                    'data' => "barista_accept_{$order->id}",
                ],
            ])->assertStatus(200);
        $this->assertEquals('preparing', $order->fresh()->status);
        $this->assertEquals($barista->id, $order->fresh()->barista_id);

        // 4. Mark completed, then verify old barista_accept_ cannot revert completed order
        $order->update(['status' => 'completed', 'completed_at' => now()]);
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-xyz'])
            ->postJson('/telegram/webhook', [
                'callback_query' => [
                    'id' => 'cb3',
                    'from' => ['id' => 200002],
                    'message' => ['chat' => ['id' => 200002]],
                    'data' => "barista_accept_{$order->id}",
                ],
            ])->assertStatus(200);
        $this->assertEquals('completed', $order->fresh()->status);
    }

    public function test_admin_unlink_telegram_clears_remember_token(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $employee->forceFill([
            'telegram_chat_id' => '555666777',
            'telegram_username' => 'emily_tg',
            'remember_token' => 'secret-remember-token',
        ])->save();

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('unlinkTelegram', $employee->id);

        $fresh = $employee->fresh();
        $this->assertNull($fresh->telegram_chat_id);
        $this->assertNull($fresh->telegram_username);
        $this->assertNull($fresh->remember_token);
    }

    public function test_deleting_or_unchecking_drink_preserves_past_order_history(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $hq = Branch::where('is_hq', true)->first();
        $room = Room::where('branch_id', $hq->id)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->where('name', 'Ice Milk Coffee')->first();

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 3)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $order = DrinkOrder::latest('id')->first();

        // Delete the drink as Admin
        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('deleteDrink', $drink->id);

        $this->assertDatabaseMissing('drink_items', ['id' => $drink->id]);
        $this->assertDatabaseHas('drink_order_items', [
            'drink_order_id' => $order->id,
            'drink_item_id' => null,
            'drink_name_snapshot' => 'Ice Milk Coffee',
            'quantity' => 3,
        ]);

        // Reports still render the drink name and total cups cleanly
        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->assertViewHas('totalCups', 3)
            ->assertSee('Milk Coffee')
            ->set('showOptionsInTable', true)
            ->assertSee('Ice Milk Coffee');
    }

    public function test_staff_create_and_edit_prevent_case_insensitive_duplicate_and_email_collision(): void
    {
        $admin = User::where('role', 'admin')->first();
        $hq = Branch::where('is_hq', true)->first();
        $staff = User::where('employee_id', 'HQ-001')->first();

        // Lowercase duplicate of existing BTK-001 triggers validation error (no 500)
        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->set('newName', 'Duplicate Staff')
            ->set('newEmployeeId', 'btk-001')
            ->set('newPassword', 'secret123')
            ->set('newPassword_confirmation', 'secret123')
            ->set('newBranchId', $hq->id)
            ->call('createStaffAccount')
            ->assertHasErrors(['newEmployeeId']);

        // Similar slug without hyphen (BTK001) generates a unique @cbvh.internal email cleanly
        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->set('newName', 'Similar Slug Staff')
            ->set('newEmployeeId', 'BTK001')
            ->set('newPassword', 'secret123')
            ->set('newPassword_confirmation', 'secret123')
            ->set('newBranchId', $hq->id)
            ->call('createStaffAccount')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['employee_id' => 'BTK001']);

        // Editing HQ-001 to lowercase 'btk-001' also fails cleanly with validation error (no 500)
        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('openEditStaffModal', $staff->id)
            ->set('editingStaffEmployeeId', 'btk-001')
            ->call('saveEditedStaff')
            ->assertHasErrors(['editingStaffEmployeeId']);
    }

    public function test_mini_app_rejects_submit_if_cart_drink_was_disabled_or_deleted(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $hq = Branch::where('is_hq', true)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        $component = Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('selectDrink', $drink->id)
            ->call('addToCart');

        // Admin disables the drink while it sits in employee's tray
        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('toggleDrinkAvailability', $drink->id);

        $component->call('submitDrinkOrder')
            ->assertSet('flashType', 'error');

        $this->assertDatabaseCount('drink_orders', 0);
    }

    public function test_generated_calendar_date_stays_locked_and_branch_switch_resets_room(): void
    {
        $admin = User::where('role', 'admin')->first();
        $hq = Branch::where('is_hq', true)->first();
        $otherBranch = Branch::where('is_hq', false)->first();
        $hqRoom = Room::where('branch_id', $hq->id)->first();
        $otherRoom = Room::where('branch_id', $otherBranch->id)->first();
        $hqDrink = DrinkItem::where('branch_id', $hq->id)->first();

        $schedule = RecurringSchedule::create([
            'title' => 'Daily Morning',
            'branch_id' => $hq->id,
            'room_id' => $hqRoom->id,
            'delivery_time' => '08:30',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        RecurringScheduleItem::create([
            'recurring_schedule_id' => $schedule->id,
            'drink_item_id' => $hqDrink->id,
            'quantity' => 5,
        ]);

        $order = DrinkOrder::create([
            'user_id' => $admin->id,
            'branch_id' => $hq->id,
            'room_id' => $hqRoom->id,
            'order_type' => 'recurring',
            'status' => 'approved',
        ]);

        $todayStr = today()->toDateString();
        RecurringScheduleDate::create([
            'recurring_schedule_id' => $schedule->id,
            'delivery_date' => $todayStr,
            'status' => 'generated',
            'generated_order_id' => $order->id,
        ]);

        $component = Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->set('activeSection', 'recurring')
            ->call('toggleScheduleDate', $todayStr)
            ->call('saveScheduleCalendarDates');

        // Generated record must stay generated (cannot be overwritten to 'off')
        $this->assertDatabaseHas('recurring_schedule_dates', [
            'recurring_schedule_id' => $schedule->id,
            'delivery_date' => $todayStr,
            'status' => 'generated',
            'generated_order_id' => $order->id,
        ]);

        // Navigating to next month must still show Today's generated status accurately
        $component->call('nextMonth')
            ->assertSee("Order #{$order->id} generated for today");

        // Switching branch resets room_id and swaps incompatible drink items
        $component->set('scheduleBranchId', $otherBranch->id)
            ->assertSet('scheduleRoomId', $otherRoom->id);
    }

    public function test_reports_exclude_rejected_orders_from_cup_totals_and_support_recurring_filter(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employee = User::where('email', 'employee@company.com')->first();
        $hq = Branch::where('is_hq', true)->first();
        $room = Room::where('branch_id', $hq->id)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        $completed = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'completed',
        ]);
        $completed->items()->create(['drink_item_id' => $drink->id, 'drink_name_snapshot' => $drink->name, 'quantity' => 3]);

        $rejected = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'rejected_by_barista',
        ]);
        $rejected->items()->create(['drink_item_id' => $drink->id, 'drink_name_snapshot' => $drink->name, 'quantity' => 5]);

        $recurring = DrinkOrder::create([
            'user_id' => $admin->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'recurring',
            'status' => 'approved',
        ]);
        $recurring->items()->create(['drink_item_id' => $drink->id, 'drink_name_snapshot' => $drink->name, 'quantity' => 4]);

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->assertViewHas('totalOrders', 3)
            ->assertViewHas('totalCups', 7)
            ->assertViewHas('recurringCount', 1)
            ->set('filterStatus', 'rejected_by_barista')
            ->assertViewHas('totalCups', 5)
            ->set('filterStatus', 'all')
            ->set('filterType', 'recurring')
            ->assertViewHas('totalOrders', 1)
            ->assertViewHas('totalCups', 4);
    }

    public function test_copy_previous_month_falls_back_to_prefill_with_count_and_reactivates_off_days(): void
    {
        $admin = User::where('role', 'admin')->first();
        $hq = Branch::where('is_hq', true)->first();

        $schedule = RecurringSchedule::create([
            'title' => 'Weekday Office',
            'branch_id' => $hq->id,
            'delivery_time' => '08:30',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        // Find the first weekday (Mon-Fri) of current month and mark it 'off'
        $firstWeekday = today()->copy()->startOfMonth();
        while ($firstWeekday->isWeekend()) {
            $firstWeekday->addDay();
        }

        RecurringScheduleDate::create([
            'recurring_schedule_id' => $schedule->id,
            'delivery_date' => $firstWeekday->toDateString(),
            'status' => 'off',
        ]);

        $count = app(RecurringOrderService::class)->copyPreviousMonth($schedule, today()->year, today()->month);

        $this->assertGreaterThan(15, $count);
        $this->assertDatabaseHas('recurring_schedule_dates', [
            'recurring_schedule_id' => $schedule->id,
            'delivery_date' => $firstWeekday->toDateString(),
            'status' => 'scheduled',
        ]);
    }

    public function test_user_can_cancel_own_approved_meeting_order_and_barista_receives_telegram_alert(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config(['services.telegram.bot_token' => 'test-token']);

        $barista = User::where('role', 'barista')->first();
        $barista->update(['telegram_chat_id' => '999888777']);

        $employee = User::where('email', 'employee@company.com')->first();
        $hq = Branch::where('is_hq', true)->first();
        $room = Room::where('branch_id', $hq->id)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        $meetingOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'meeting',
            'delivery_time' => now()->addDay(),
            'status' => 'approved',
        ]);
        $meetingOrder->items()->create(['drink_item_id' => $drink->id, 'drink_name_snapshot' => $drink->name, 'quantity' => 4]);

        $instantOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'approved',
        ]);

        $laterTodayQuickOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'delivery_time' => now()->addHours(2),
            'status' => 'approved',
        ]);
        $laterTodayQuickOrder->items()->create(['drink_item_id' => $drink->id, 'drink_name_snapshot' => $drink->name, 'quantity' => 2]);

        $preparingMeeting = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'meeting',
            'delivery_time' => now()->addDay(),
            'status' => 'preparing',
        ]);

        Livewire::actingAs($employee)
            ->test('mini-app.booking-app')
            ->call('cancelMeetingOrder', $meetingOrder->id)
            ->assertSet('flashType', 'success')
            ->call('cancelMeetingOrder', $laterTodayQuickOrder->id)
            ->assertSet('flashType', 'success')
            ->call('cancelMeetingOrder', $instantOrder->id)
            ->assertSet('flashType', 'error')
            ->call('cancelMeetingOrder', $preparingMeeting->id)
            ->assertSet('flashType', 'error');

        $this->assertEquals('cancelled', $meetingOrder->fresh()->status);
        $this->assertEquals('cancelled', $laterTodayQuickOrder->fresh()->status);
        $this->assertEquals('approved', $instantOrder->fresh()->status);
        $this->assertEquals('preparing', $preparingMeeting->fresh()->status);

        Http::assertSent(function ($request) use ($meetingOrder) {
            return str_contains($request->url(), 'sendMessage')
                && ($request['chat_id'] ?? null) === '999888777'
                && str_contains($request['text'] ?? '', "ORDER CANCELLED (#{$meetingOrder->id})");
        });

        // Barista Kitchen Display shows the red web alert banner and dismisses it cleanly (persisting across page refresh)
        Livewire::actingAs($barista)
            ->test('barista.kitchen-display')
            ->assertSee(__('cbvh.barista_cancelled_banner', ['id' => $meetingOrder->id]))
            ->assertSee(__('cbvh.barista_cancelled_banner', ['id' => $laterTodayQuickOrder->id]))
            ->call('dismissCancelledAlert', $meetingOrder->id)
            ->assertDontSee(__('cbvh.barista_cancelled_banner', ['id' => $meetingOrder->id]))
            ->assertSee(__('cbvh.barista_cancelled_banner', ['id' => $laterTodayQuickOrder->id]));

        // Simulate browser refresh (fresh component mount)
        $baristaScreen = Livewire::actingAs($barista)
            ->test('barista.kitchen-display')
            ->assertDontSee(__('cbvh.barista_cancelled_banner', ['id' => $meetingOrder->id]))
            ->assertSee(__('cbvh.barista_cancelled_banner', ['id' => $laterTodayQuickOrder->id]));

        // Simulate new order arriving & existing order cancelled during live poll
        $newOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'approved',
        ]);
        $instantOrder->update(['status' => 'cancelled']);

        $baristaScreen->call('$refresh')
            ->assertDispatched('new-barista-order', orderType: 'quick')
            ->assertDispatched('cancelled-barista-order', orderType: 'cancelled_quick');
    }

    public function test_admin_can_cancel_active_order_and_cancelled_orders_are_excluded_from_cup_totals(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config(['services.telegram.bot_token' => 'test-token']);

        $admin = User::where('role', 'admin')->first();
        $barista = User::where('role', 'barista')->first();
        $barista->update(['telegram_chat_id' => '999888777']);

        $employee = User::where('email', 'employee@company.com')->first();
        $employee->update(['telegram_chat_id' => '111222333']);

        $hq = Branch::where('is_hq', true)->first();
        $room = Room::where('branch_id', $hq->id)->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        $preparingOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'preparing',
        ]);
        $preparingOrder->items()->create(['drink_item_id' => $drink->id, 'drink_name_snapshot' => $drink->name, 'quantity' => 3]);

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('cancelOrder', $preparingOrder->id)
            ->assertSet('flashType', 'success')
            ->assertViewHas('totalCups', 0);

        $this->assertEquals('cancelled', $preparingOrder->fresh()->status);

        Http::assertSent(fn ($req) => ($req['chat_id'] ?? null) === '999888777');
        Http::assertSent(fn ($req) => ($req['chat_id'] ?? null) === '111222333');
    }
}
