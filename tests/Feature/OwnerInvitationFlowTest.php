<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\OwnerInvitationNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OwnerInvitationFlowTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshSafeDatabase;
    }

    private const PASSWORD = 'ZemboaOwnerSecure123!';

    protected function refreshTestDatabase(): void
    {
        if (
            config('database.default') !== 'sqlite' ||
            config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new RuntimeException(
                'Tests interdits hors SQLite :memory:.'
            );
        }

        $this->refreshSafeDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->seed(DatabaseSeeder::class);
    }

    private function prepareStore(): array
    {
        $admin = User::factory()->create([
            'store_id' => null,
            'status' => 'ACTIVE',
            'is_buyer' => false,
        ]);

        $adminRole = Role::query()
            ->where('code', 'SUPER_ADMIN')
            ->firstOrFail();

        $admin->roles()->attach($adminRole->id);

        $this->actingAs($admin);

        $slug = 'store-'.Str::lower(Str::random(10));
        $email = 'owner-'.Str::lower(Str::random(10))
            .'@example.test';

        $this->postJson(route('admin.stores.store'), [
            'name' => 'Boutique ZEMBOA Audit',
            'slug' => $slug,
            'owner_name' => 'Propriétaire Audit',
            'owner_email' => $email,
        ])
            ->assertCreated()
            ->assertJsonPath('store.status', 'PENDING')
            ->assertJsonPath('owner.status', 'INVITED');

        $store = Store::where('slug', $slug)
            ->firstOrFail();

        $owner = User::where('email', $email)
            ->firstOrFail();

        return [$admin, $store, $owner];
    }

    private function sendInvitation(
        Store $store,
        User $owner
    ): string {
        $this->postJson(
            route('admin.stores.owner-invitation.send', [
                'storeId' => $store->id,
            ])
        )->assertOk();

        $sent = Notification::sent(
            $owner,
            OwnerInvitationNotification::class
        );

        $this->assertNotEmpty($sent);

        $notification = $sent[count($sent) - 1];

        $url = $notification->toMail($owner)->actionUrl;

        $path = parse_url($url, PHP_URL_PATH);

        $this->assertIsString($path);

        $token = basename($path);

        $this->assertTrue(
            Password::broker('users')
                ->tokenExists($owner, $token)
        );

        return $token;
    }

    private function acceptInvitation(
        User $owner,
        string $token
    ) {
        return $this->postJson(
            route('owner.invitation.accept', [
                'token' => $token,
            ]),
            [
                'email' => $owner->email,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ]
        );
    }

    public function test_guest_cannot_send_invitation(): void
    {
        [, $store] = $this->prepareStore();

        auth('web')->logout();

        $this->postJson(
            route('admin.stores.owner-invitation.send', [
                'storeId' => $store->id,
            ])
        )->assertUnauthorized();

        Notification::assertNothingSent();
    }

    public function test_store_owner_cannot_send_invitation(): void
    {
        [, $store, $owner] = $this->prepareStore();

        $this->actingAs($owner);

        $this->postJson(
            route('admin.stores.owner-invitation.send', [
                'storeId' => $store->id,
            ])
        )->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_admin_can_invite_pending_owner(): void
    {
        [, $store, $owner] = $this->prepareStore();

        $token = $this->sendInvitation($store, $owner);

        $this->get(route('owner.invitation.form', [
            'token' => $token,
            'email' => $owner->email,
        ]))->assertOk();

        Notification::assertSentTo(
            $owner,
            OwnerInvitationNotification::class
        );

        $this->assertSame('PENDING', $store->fresh()->status);
        $this->assertSame('INVITED', $owner->fresh()->status);
    }

    public function test_pending_owner_can_accept_without_activating_store(): void
    {
        [, $store, $owner] = $this->prepareStore();

        $token = $this->sendInvitation($store, $owner);

        $this->acceptInvitation($owner, $token)
            ->assertOk();

        $owner->refresh();

        $this->assertSame('ACTIVE', $owner->status);
        $this->assertNotNull($owner->email_verified_at);

        $this->assertTrue(
            $owner->hasRole('STORE_OWNER')
        );

        $this->assertSame(
            'PENDING',
            $store->fresh()->status
        );

        // Le propriétaire est activé, mais la
        // boutique n'est pas encore approuvée.
        $this->actingAs($owner);

        $this->getJson(route('account.show'))
            ->assertForbidden();
    }

    public function test_invitation_cannot_be_used_twice(): void
    {
        [, $store, $owner] = $this->prepareStore();

        $token = $this->sendInvitation($store, $owner);

        $this->acceptInvitation($owner, $token)
            ->assertOk();

        $this->acceptInvitation($owner, $token)
            ->assertUnprocessable();

        $this->assertFalse(
            Password::broker('users')
                ->tokenExists($owner, $token)
        );
    }

    public function test_expired_invitation_is_rejected(): void
    {
        [, $store, $owner] = $this->prepareStore();

        $token = $this->sendInvitation($store, $owner);

        $this->travel(61)->minutes();

        $this->get(route('owner.invitation.form', [
            'token' => $token,
            'email' => $owner->email,
        ]))->assertNotFound();

        $this->acceptInvitation($owner, $token)
            ->assertUnprocessable();

        $this->assertSame(
            'INVITED',
            $owner->fresh()->status
        );

        $this->travelBack();
    }

    public function test_resending_invitation_invalidates_old_token(): void
    {
        [, $store, $owner] = $this->prepareStore();

        $oldToken = $this->sendInvitation($store, $owner);
        $newToken = $this->sendInvitation($store, $owner);

        $this->assertNotSame($oldToken, $newToken);

        $broker = Password::broker('users');

        $this->assertFalse(
            $broker->tokenExists($owner, $oldToken)
        );

        $this->assertTrue(
            $broker->tokenExists($owner, $newToken)
        );

        $this->acceptInvitation($owner, $newToken)
            ->assertOk();

        $this->assertSame(
            'ACTIVE',
            $owner->fresh()->status
        );
    }

    public function test_inactive_store_blocks_invitation(): void
    {
        [, $store, $owner] = $this->prepareStore();

        $store->status = 'INACTIVE';
        $store->save();

        $this->postJson(
            route('admin.stores.owner-invitation.send', [
                'storeId' => $store->id,
            ])
        )->assertStatus(409);

        Notification::assertNothingSent();

        $this->assertSame(
            'INVITED',
            $owner->fresh()->status
        );
    }

    public function test_conflicting_owner_role_blocks_activation(): void
    {
        [, $store, $owner] = $this->prepareStore();

        $token = $this->sendInvitation($store, $owner);

        $cashierRole = Role::query()
            ->where('code', 'CASHIER')
            ->firstOrFail();

        $owner->roles()->attach($cashierRole->id);

        $this->get(route('owner.invitation.form', [
            'token' => $token,
            'email' => $owner->email,
        ]))->assertNotFound();

        $this->acceptInvitation($owner, $token)
            ->assertUnprocessable();

        $this->assertSame(
            'INVITED',
            $owner->fresh()->status
        );
    }

    public function test_activated_owner_cannot_be_reinvited(): void
    {
        [, $store, $owner] = $this->prepareStore();

        $token = $this->sendInvitation($store, $owner);

        $this->acceptInvitation($owner, $token)
            ->assertOk();

        $this->postJson(
            route('admin.stores.owner-invitation.send', [
                'storeId' => $store->id,
            ])
        )->assertStatus(409);

        Notification::assertSentToTimes(
            $owner,
            OwnerInvitationNotification::class,
            1
        );
    }
}
