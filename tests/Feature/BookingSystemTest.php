<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DrinkItem;
use App\Models\DrinkOrder;
use App\Models\DrinkOrderItem;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $fields = ['auth_date' => '1700000000', 'user' => $tgUserJson];
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
        $lounge = Room::where('branch_id', $hq->id)->where('name', 'Executive Lounge')->first();
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
            ->set('peopleCount', 8)
            ->set('attachment', $file)
            ->call('selectDrink', $drink->id)
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

    public function test_package_delivery_user_confirm_and_15_minute_auto_complete(): void
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

        $forgotOrder = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $room->id,
            'order_type' => 'instant',
            'status' => 'in_delivery',
            'in_delivery_at' => now()->subMinutes(16),
        ]);

        $this->assertSame(1, DrinkOrder::autoCompleteExpiredDeliveries());
        $this->assertDatabaseHas('drink_orders', [
            'id' => $forgotOrder->id,
            'status' => 'completed',
            'auto_completed' => true,
        ]);
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
            ->set('newEmail', 'bopha@company.com')
            ->set('newPassword', 'secret123')
            ->set('newBranchId', $hq->id)
            ->set('newRole', 'employee')
            ->call('createStaffAccount')
            ->set('filterType', 'instant')
            ->call('exportCsv')
            ->assertFileDownloaded();

        $this->assertDatabaseHas('users', [
            'email' => 'bopha@company.com',
            'branch_id' => $hq->id,
            'role' => 'employee',
        ]);
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

        $this->assertSame('Latte', \App\Support\CbvhLocale::toBaseDrinkName('Hot Latte'));
        $this->assertSame('Latte', \App\Support\CbvhLocale::toBaseDrinkName('Iced Latte'));
        $this->assertSame('Green Tea', \App\Support\CbvhLocale::toBaseDrinkName('Iced Green Tea Latte'));
        $this->assertSame('Americano', \App\Support\CbvhLocale::toBaseDrinkName('Iced Americano'));

        Livewire::actingAs($admin)
            ->test('admin.approval-portal')
            ->call('exportDrinkTotalsCsv')
            ->assertFileDownloaded();
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
            ]
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
        $lounge = Room::where('branch_id', $hq->id)->where('name', 'Executive Lounge')->first();
        $americano = DrinkItem::where('branch_id', $hq->id)->where('name', 'Iced Americano')->first();

        $order = DrinkOrder::create([
            'user_id' => $employee->id,
            'branch_id' => $hq->id,
            'room_id' => $lounge->id,
            'order_type' => 'instant',
            'status' => 'approved',
            'people_count' => 2,
            'estimated_wait_minutes' => 4,
            'delivery_time' => now()->addMinutes(4),
        ]);
        $order->items()->create([
            'drink_item_id' => $americano->id,
            'quantity' => 2,
            'selected_options' => [
                'sugar' => '50%',
                'coffee' => 'Single Shot',
                'ice' => 'Less Ice',
            ],
        ]);

        $response = $this->actingAs($barista)->get('/barista');

        $response->assertStatus(200)
            ->assertSee('<title>អេក្រង់កន្លែងឆុងភេសជ្ជៈ</title>', false)
            ->assertSee('អេក្រង់កន្លែងឆុងភេសជ្ជៈ')
            ->assertSee('CBVH ការិយាល័យកណ្តាល (បឹងកក់)')
            ->assertSee('កន្លែងទទួលភ្ញៀវពិសេស')
            ->assertSee('ជាន់ទី ១')
            ->assertSee('អាមេរិកាណូទឹកកក')
            ->assertSee('១ ស៊ុត')
            ->assertSee('ទឹកកកតិច')
            ->assertSee('រសៀល')
            ->assertSee('ឆាប់បំផុត')
            ->assertSee('កម្ម៉ង់ម៉ោង')
            ->assertSee('១. ទទួលការកម្ម៉ង់')
            ->assertDontSee('Barista Kitchen Display')
            ->assertDontSee('Barista Display')
            ->assertDontSee('Iced Americano')
            ->assertDontSee('Executive Lounge')
            ->assertDontSee('Single Shot')
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
            ->assertSee('CBVH សាខាបាក់ទូក')
            ->assertSee('បន្ទប់ប្រជុំសាខា')
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

    public function test_quick_drink_daily_limit_is_10_cups_per_user_while_meeting_has_no_limit(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 10, 0, 0));

        $hq = Branch::where('is_hq', true)->first();
        $hqUser = User::where('email', 'employee@company.com')->first();
        $admin = User::where('email', 'admin@company.com')->first();
        $drink = DrinkItem::where('branch_id', $hq->id)->first();

        // 1. User orders 6 Quick Drink cups -> succeeds (4 remaining today)
        Livewire::actingAs($hqUser)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'instant')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 6)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        // 2. Trying to add 5 more Quick Drink cups (6 + 5 = 11 > 10) is blocked
        Livewire::actingAs($hqUser)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'instant')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 5)
            ->call('addToCart')
            ->assertSet('flashType', 'error')
            ->assertSet('drinkCart', []);

        // 3. Ordering the remaining 4 Quick Drink cups (6 + 4 = 10) succeeds
        Livewire::actingAs($hqUser)
            ->test('mini-app.booking-app')
            ->call('setOrderType', 'instant')
            ->call('selectDrink', $drink->id)
            ->set('drinkQuantity', 4)
            ->call('addToCart')
            ->call('submitDrinkOrder')
            ->assertSet('flashType', 'success');

        $this->assertSame(10, DrinkOrder::quickCupsUsedToday($hqUser->id));

        // 4. Even at 10/10 Quick cups, the same user can order 15 Meeting Drink cups (no limit)
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

        // 5. A different user still has their own 10-cup Quick Drink quota today
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
}
