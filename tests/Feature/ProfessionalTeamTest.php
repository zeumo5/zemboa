<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\EmployeeInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

class ProfessionalTeamTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new RuntimeException('Only uncached SQLite :memory: allowed.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function professional(string $code = 'STORE_OWNER', ?Store $store = null): User
    {
        $store ??= Store::create(['name' => 'Fictional test store', 'slug' => 'test-'.fake()->unique()->numerify('########'), 'status' => 'ACTIVE']);
        $role = Role::firstOrCreate(['code' => $code], ['name' => $code]);
        $user = User::factory()->create(['name' => 'Fictional team member', 'email' => fake()->unique()->numerify('fixture-########').'@example.test', 'store_id' => $store->id, 'status' => 'ACTIVE', 'is_buyer' => false, 'email_verified_at' => now()]);
        $user->roles()->attach($role);

        return $user;
    }

    private function payload(array $extra = []): array
    {
        Role::firstOrCreate(['code' => 'STORE_MANAGER'], ['name' => 'Manager']);

        return array_replace(['name' => 'Fictional invited manager', 'email' => 'new-manager@example.test', 'phone' => '690 123 456', 'role' => 'STORE_MANAGER'], $extra);
    }

    public function test_owner_html_and_json_use_same_invitation_backend_without_exposing_secrets(): void
    {
        $owner = $this->professional();
        $this->actingAs($owner, 'web')->post('/employees', $this->payload())->assertRedirect(route('employees.index'));
        $employee = User::where('email', 'new-manager@example.test')->firstOrFail();
        $this->assertSame('+237690123456', $employee->phone);
        $this->assertSame($owner->store_id, $employee->store_id);
        $this->assertFalse($employee->is_buyer);
        $this->assertSame(['STORE_MANAGER'], $employee->roles()->pluck('code')->all());
        Notification::assertSentTo($employee, EmployeeInvitationNotification::class);
        $this->get('/employees')->assertOk()->assertSee(__('team.title'))->assertSee(__('team.states.pending'))->assertDontSee($employee->password);
        $this->getJson('/employees')->assertOk()->assertJsonPath('data.0.phone', '+237690123456')->assertDontSee($employee->password)->assertDontSee('"token"', false);
    }

    public function test_only_exclusive_active_owner_can_manage_team(): void
    {
        foreach (['STORE_MANAGER', 'CASHIER', 'DELIVERY_AGENT', 'SUPER_ADMIN'] as $role) {
            $actor = $this->professional($role);
            $this->actingAs($actor, 'web')->get('/employees')->assertForbidden();
            $this->postJson('/employees', $this->payload())->assertForbidden();
        }
        $owner = $this->professional();
        $owner->roles()->attach(Role::firstOrCreate(['code' => 'FUTURE_ROLE'], ['name' => 'Unknown']));
        $this->actingAs($owner, 'web')->get('/employees')->assertForbidden();
        $owner->roles()->detach();
        $owner->roles()->attach(Role::where('code', 'STORE_OWNER')->first());
        $owner->forceFill(['status' => 'INACTIVE'])->save();
        $this->actingAs($owner, 'web')->get('/employees')->assertForbidden();
    }

    public function test_foreign_team_is_neither_visible_nor_mutable(): void
    {
        $owner = $this->professional();
        $foreign = $this->professional('CASHIER');
        $this->actingAs($owner, 'web')->get('/employees')->assertOk()->assertDontSee($foreign->email);
        foreach (['status' => ['status' => 'INACTIVE'], 'role' => ['role' => 'STORE_MANAGER']] as $action => $payload) {
            $this->patchJson('/employees/'.$foreign->id.'/'.$action, $payload)->assertNotFound();
        }
        $this->postJson('/employees/'.$foreign->id.'/invitation')->assertNotFound();
        $this->deleteJson('/employees/'.$foreign->id.'/invitation')->assertNotFound();
    }

    public function test_injected_role_tenant_and_buyer_status_are_rejected(): void
    {
        $this->actingAs($this->professional(), 'web');
        foreach ([['role' => 'SUPER_ADMIN'], ['role' => 'STORE_OWNER'], ['store_id' => 99], ['is_buyer' => true], ['roles' => ['SUPER_ADMIN']], ['phone' => 'not-a-phone'], ['phone' => '']] as $extra) {
            $this->postJson('/employees', $this->payload($extra))->assertUnprocessable();
        }
        $this->assertFalse(User::where('email', 'new-manager@example.test')->exists());
        Notification::assertNothingSent();
    }

    public function test_existing_buyer_or_professional_is_never_linked(): void
    {
        $this->actingAs($this->professional(), 'web');
        $buyer = User::factory()->create(['email' => 'new-manager@example.test', 'is_buyer' => true, 'phone' => '+237690123456']);
        $this->postJson('/employees', $this->payload())->assertUnprocessable();
        $this->postJson('/employees', $this->payload(['email' => 'another@example.test']))->assertUnprocessable();
        $this->assertNull($buyer->fresh()->store_id);
        $this->assertSame(0, $buyer->roles()->count());
    }

    public function test_resend_replaces_expired_link_and_revoke_disables_it(): void
    {
        $this->actingAs($this->professional(), 'web')->postJson('/employees', $this->payload())->assertCreated();
        $employee = User::where('email', 'new-manager@example.test')->firstOrFail();
        $first = Notification::sent($employee, EmployeeInvitationNotification::class)->first();
        $firstToken = basename(parse_url($first->toMail($employee)->actionUrl, PHP_URL_PATH));
        $this->postJson('/employees/'.$employee->id.'/invitation')->assertUnprocessable();
        $this->travel(61)->minutes();
        $this->get('/employee-invitations/'.$firstToken.'?email='.$employee->email)->assertNotFound();
        $this->get('/employees')->assertSee(__('team.states.expired'));
        $this->postJson('/employees/'.$employee->id.'/invitation')->assertOk();
        $notification = Notification::sent($employee, EmployeeInvitationNotification::class)->last();
        $token = basename(parse_url($notification->toMail($employee)->actionUrl, PHP_URL_PATH));
        $this->assertFalse(Password::broker('users')->tokenExists($employee, $firstToken));
        $this->get('/employee-invitations/'.$token.'?email='.$employee->email)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->deleteJson('/employees/'.$employee->id.'/invitation')->assertOk();
        $this->assertFalse(Password::broker('users')->tokenExists($employee, $token));
        $this->post('/employee-invitations/'.$token, ['email' => $employee->email, 'password' => 'Fictional-test-password-123', 'password_confirmation' => 'Fictional-test-password-123'])->assertSessionHasErrors();
        $this->assertSame('INVITED', $employee->fresh()->status);
    }

    public function test_failed_notification_rolls_back_new_account_role_and_token(): void
    {
        $this->actingAs($this->professional(), 'web');
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Fictional mail failure'));
        $this->postJson('/employees', $this->payload())->assertStatus(500);
        $this->assertFalse(User::where('email', 'new-manager@example.test')->exists());
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', 'new-manager@example.test')->exists());
    }

    public function test_disabling_employee_blocks_existing_professional_session(): void
    {
        $owner = $this->professional();
        $employee = $this->professional('STORE_MANAGER', $owner->store);
        $this->actingAs($owner, 'web')->patchJson('/employees/'.$employee->id.'/status', ['status' => 'INACTIVE'])->assertOk();
        $this->actingAs($employee->fresh(), 'web')->getJson('/stock')->assertForbidden();
        $this->actingAs($owner, 'web')->patchJson('/employees/'.$employee->id.'/status', ['status' => 'ACTIVE'])->assertOk();
        $this->assertSame('ACTIVE', $employee->fresh()->status);
    }

    public function test_manager_dashboard_is_real_and_has_no_team_management_link(): void
    {
        $manager = $this->professional('STORE_MANAGER');
        $permission = Permission::create(['code' => 'orders.view', 'name' => 'View orders']);
        $manager->roles()->first()->permissions()->attach($permission);
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            session(['locale' => $locale]);
            $response = $this->actingAs($manager, 'web')->get('/espace-professionnel')->assertOk()->assertSee(__('team.manager_space'))
                ->assertDontSee('href="'.route('employees.index').'"', false)->assertDontSee('href="'.route('store.settings.show').'"', false);
            $this->captureFixture('manager-'.$locale, $response->getContent());
        }
    }

    public function test_filters_and_bilingual_pages_use_real_values(): void
    {
        $owner = $this->professional();
        $this->professional('CASHIER', $owner->store);
        $this->actingAs($owner, 'web')->get('/employees?role=STORE_MANAGER')->assertOk()->assertSee(__('team.empty'));
        $this->postJson('/employees', $this->payload())->assertCreated();
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            session(['locale' => $locale]);
            $response = $this->get('/employees');
            $response->assertOk()->assertSee(__('team.title'))->assertSee('aria-current="page"', false);
            $this->captureFixture('team-'.$locale, $response->getContent());
        }
    }

    private function captureFixture(string $name, string $html): void
    {
        if (getenv('ZEMBOA_RENDER_FIXTURES') !== '1') {
            return;
        }
        $directory = storage_path('demo/phase19a-preview');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}
