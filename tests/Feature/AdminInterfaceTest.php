<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\OwnerInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminInterfaceTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Admin tests require uncached SQLite :memory:.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    private function actor(string $code = 'SUPER_ADMIN'): User
    {
        $user = User::factory()->create(['store_id' => null, 'status' => 'ACTIVE', 'is_buyer' => false]);
        $role = Role::firstOrCreate(['code' => $code], ['name' => $code]);
        $user->roles()->attach($role);

        return $user;
    }

    private function payload(): array
    {
        return ['name' => 'Memory Store', 'slug' => 'memory-store', 'owner_name' => 'Fictional Owner', 'owner_email' => 'owner@example.test'];
    }

    private function asAdmin(): User
    {
        Role::firstOrCreate(['code' => 'STORE_OWNER'], ['name' => 'Owner']);
        $actor = $this->actor();
        $this->actingAs($actor, 'web');

        return $actor;
    }

    public function test_html_pages_and_all_mutations_reject_non_admin_roles(): void
    {
        $store = Store::create(['name' => 'Private store', 'slug' => 'private', 'status' => 'PENDING']);
        foreach (['STORE_OWNER', 'STORE_MANAGER', 'CASHIER', 'DELIVERY_AGENT', 'BUYER'] as $role) {
            $actor = $this->actor($role);
            $this->actingAs($actor, 'web');
            foreach (['/admin', '/admin/stores', '/admin/stores/create', '/admin/stores/'.$store->id] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/admin/stores', $this->payload())->assertForbidden();
            $this->post('/admin/stores/'.$store->id.'/owner-invitation')->assertForbidden();
            $this->patch('/admin/stores/'.$store->id.'/status', ['status' => 'ACTIVE', 'reason' => 'A valid test reason'])->assertForbidden();
        }
        $this->assertDatabaseCount('stores', 1);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_buyer_guard_alone_does_not_open_admin_routes(): void
    {
        $buyer = User::factory()->create(['is_buyer' => true, 'status' => 'ACTIVE']);
        $this->actingAs($buyer, 'buyer');
        Auth::shouldUse('web');
        $this->get('/admin')->assertRedirect(route('login'));
        $this->postJson('/admin/stores', $this->payload())->assertUnauthorized();
    }

    public function test_mixed_roles_inactive_accounts_and_store_attached_admins_are_denied(): void
    {
        $actor = $this->asAdmin();
        $actor->roles()->attach(Role::where('code', 'STORE_OWNER')->sole());
        $this->get('/admin')->assertForbidden();
        $actor->roles()->detach(Role::where('code', 'STORE_OWNER')->sole());
        $actor->forceFill(['status' => 'INACTIVE'])->save();
        $this->get('/admin')->assertForbidden();
        $store = Store::create(['name' => 'Attached', 'slug' => 'attached', 'status' => 'ACTIVE']);
        $actor->forceFill(['status' => 'ACTIVE', 'store_id' => $store->id])->save();
        $this->get('/admin')->assertForbidden();
    }

    public function test_creation_ignores_injected_privileges_and_can_send_a_safe_owner_invitation(): void
    {
        $this->asAdmin();
        Notification::fake();
        $this->post('/admin/stores', $this->payload() + ['status' => 'ACTIVE', 'role' => 'SUPER_ADMIN', 'store_id' => 999, 'is_buyer' => true])
            ->assertRedirect();
        $store = Store::where('slug', 'memory-store')->sole();
        $owner = User::where('email', 'owner@example.test')->sole();
        $this->assertSame('PENDING', $store->status);
        $this->assertSame('INVITED', $owner->status);
        $this->assertSame($store->id, $owner->store_id);
        $this->assertFalse((bool) $owner->is_buyer);
        $this->assertEquals(['STORE_OWNER'], $owner->roles()->pluck('code')->all());
        $this->assertNull($owner->email_verified_at);
        $this->get('/admin/stores/'.$store->id)->assertOk()->assertSee(__('admin.invite'));
        $this->post('/admin/stores/'.$store->id.'/owner-invitation')->assertRedirect(route('admin.stores.show', ['storeId' => $store->id]));
        Notification::assertSentTo($owner, OwnerInvitationNotification::class);
        $notification = Notification::sent($owner, OwnerInvitationNotification::class)->first();
        $token = basename(parse_url($notification->toMail($owner)->actionUrl, PHP_URL_PATH));
        $this->assertTrue(Password::broker('users')->tokenExists($owner, $token));
        $this->assertNotEquals($token, DB::table('password_reset_tokens')->where('email', $owner->email)->value('token'));
        $this->patch('/admin/stores/'.$store->id.'/status', ['status' => 'ACTIVE', 'reason' => 'Owner not yet verified'])->assertSessionHasErrors('status');
        $this->assertSame('PENDING', $store->fresh()->status);
    }

    public function test_duplicates_and_existing_buyer_email_never_link_or_modify_a_user(): void
    {
        $this->asAdmin();
        $buyer = User::factory()->create(['email' => 'existing@example.test', 'is_buyer' => true]);
        $before = $buyer->fresh()->getRawOriginal();
        $this->postJson('/admin/stores', array_replace($this->payload(), ['owner_email' => 'EXISTING@example.test']))->assertUnprocessable()->assertJsonValidationErrors('owner_email');
        $this->assertEquals($before, $buyer->fresh()->getRawOriginal());
        $this->assertDatabaseCount('stores', 0);
        $this->post('/admin/stores', $this->payload())->assertRedirect();
        $this->postJson('/admin/stores', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['slug', 'owner_email']);
        $this->assertDatabaseCount('stores', 1);
    }

    public function test_missing_owner_role_rolls_back_creation(): void
    {
        $this->actingAs($this->actor(), 'web');
        $this->postJson('/admin/stores', $this->payload())->assertNotFound();
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_failure_after_store_insert_rolls_back_the_entire_creation(): void
    {
        $this->asAdmin();
        Event::listen('eloquent.creating: '.User::class, function (User $user): void {
            if ($user->store_id !== null) {
                throw new \RuntimeException('Simulated owner persistence failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/stores', $this->payload());
            $this->fail('Creation should have failed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated owner persistence failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('role_user', 1);
    }

    public function test_filters_pagination_status_history_and_long_names_are_safe(): void
    {
        $this->asAdmin();
        for ($i = 0; $i < 22; $i++) {
            Store::create(['name' => 'Long name '.str_repeat('x', 120).$i, 'slug' => 'store-'.$i, 'status' => $i === 0 ? 'ACTIVE' : 'PENDING']);
        }
        $this->get('/admin/stores')->assertOk()->assertViewHas('stores', fn ($stores) => $stores->count() === 20 && $stores->total() === 22);
        $this->get('/admin/stores?status=ACTIVE&q=store-0')->assertOk()->assertViewHas('stores', fn ($stores) => $stores->total() === 1);
        $this->get('/admin')->assertOk()->assertViewHas('counts', fn ($counts) => (int) $counts->get('ACTIVE') === 1 && (int) $counts->get('PENDING') === 21);
        $store = Store::where('slug', 'store-0')->sole();
        $this->patch('/admin/stores/'.$store->id.'/status', ['status' => 'INACTIVE', 'reason' => 'Controlled test suspension'])->assertRedirect();
        $this->assertDatabaseHas('store_status_events', ['store_id' => $store->id, 'old_status' => 'ACTIVE', 'new_status' => 'INACTIVE']);
        $this->get('/admin/stores/'.$store->id)->assertOk()->assertSee('Controlled test suspension');
    }

    public function test_invitation_rejects_ambiguous_owner_and_disabled_store(): void
    {
        $this->asAdmin();
        Notification::fake();
        $this->post('/admin/stores', $this->payload());
        $store = Store::where('slug', 'memory-store')->sole();
        $store->update(['status' => 'INACTIVE']);
        $this->postJson('/admin/stores/'.$store->id.'/owner-invitation')->assertStatus(409);
        $store->update(['status' => 'PENDING']);
        $other = User::factory()->create(['store_id' => $store->id, 'status' => 'INVITED', 'email_verified_at' => null]);
        $other->roles()->attach(Role::where('code', 'STORE_OWNER')->sole());
        $this->postJson('/admin/stores/'.$store->id.'/owner-invitation')->assertStatus(409);
        Notification::assertNothingSent();
    }

    public function test_translated_pages_render_with_only_fictional_memory_data(): void
    {
        $admin = $this->asAdmin();
        $this->post('/admin/stores', $this->payload());
        $store = Store::where('slug', 'memory-store')->sole();
        $store->update(['name' => 'Memory Store — '.str_repeat('Long fictional store name ', 4)]);
        foreach (['fr', 'en'] as $locale) {
            $admin->forceFill(['preferred_locale' => $locale])->save();
            foreach (['dashboard' => '/admin', 'stores' => '/admin/stores', 'create' => '/admin/stores/create', 'detail' => '/admin/stores/'.$store->id] as $name => $path) {
                $response = $this->get($path)->assertOk()->assertSee('lang="'.$locale.'"', false);
                if (getenv('ZEMBOA_ADMIN_RENDER') === '1') {
                    $dir = storage_path('demo/audits/admin-18b/rendered');
                    if (! is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }
                    file_put_contents($dir.'/'.$name.'-'.$locale.'.html', $response->getContent());
                }
            }
        }
    }
}
