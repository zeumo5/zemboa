<?php

namespace Tests\Feature;

use App\Logging\RedactInvitationSecrets;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

class InvitationPrivacyTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Only uncached SQLite :memory: allowed.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    private function owner(): User
    {
        $store = Store::create(['name' => 'Fictional store', 'slug' => 'privacy', 'status' => 'PENDING']);
        $owner = User::factory()->create(['store_id' => $store->id, 'status' => 'INVITED', 'email_verified_at' => null]);
        $owner->roles()->attach(Role::create(['name' => 'Owner', 'code' => 'STORE_OWNER']));

        return $owner;
    }

    private function assertProtected($response): void
    {
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_valid_owner_page_validation_and_success_are_not_cached_or_referred(): void
    {
        $owner = $this->owner();
        $token = Password::broker('users')->createToken($owner);
        $path = '/owner-invitations/'.$token;
        $this->assertProtected($this->get($path.'?email='.urlencode($owner->email))->assertOk());
        $invalid = $this->postJson($path, ['email' => $owner->email, 'password' => 'short', 'password_confirmation' => 'short']);
        $invalid->assertUnprocessable();
        $this->assertProtected($invalid);
        $response = $this->postJson($path, ['email' => $owner->email, 'password' => 'Fictional-owner-password-123', 'password_confirmation' => 'Fictional-owner-password-123']);
        $response->assertOk();
        $this->assertProtected($response);
        $this->assertSame('ACTIVE', $owner->fresh()->status);
        $this->assertSame('PENDING', $owner->store->fresh()->status);
        $this->postJson($path, ['email' => $owner->email, 'password' => 'Fictional-owner-password-123', 'password_confirmation' => 'Fictional-owner-password-123'])->assertUnprocessable();
    }

    public function test_invalid_owner_and_employee_pages_hide_tokens_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        foreach (['owner', 'employee'] as $type) {
            $token = 'fictional-private-token-for-test';
            $response = $this->get('/'.$type.'-invitations/'.$token.'?email=private%40example.test');
            $response->assertNotFound()->assertDontSee($token)->assertDontSee('private@example.test');
            $this->assertProtected($response);
        }
    }

    public function test_invitation_validation_does_not_flash_passwords_or_tokens(): void
    {
        $owner = $this->owner();
        $token = Password::broker('users')->createToken($owner);
        $this->from('/owner-invitations/'.$token)->post('/owner-invitations/'.$token, [
            'email' => $owner->email, 'password' => 'short', 'password_confirmation' => 'different', 'token' => $token,
        ])->assertRedirect()->assertSessionHasErrors('password');
        $old = session()->getOldInput();
        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('password_confirmation', $old);
        $this->assertArrayNotHasKey('token', $old);
    }

    public function test_logging_masks_urls_context_and_exception_messages_without_writing_files(): void
    {
        $monolog = new Logger('privacy');
        $handler = new TestHandler;
        $monolog->pushHandler($handler);
        $logger = new \Illuminate\Log\Logger($monolog);
        (new RedactInvitationSecrets)($logger);
        $token = 'fictional-sensitive-token';
        $logger->error('Failure /owner-invitations/'.$token.'?email=private%40example.test', [
            'token' => $token, 'password' => 'fictional-password-value', 'nested' => ['email' => 'private@example.test'],
            'exception' => new \RuntimeException('Failed at /employee-invitations/'.$token),
        ]);
        $monolog->pushProcessor(new PsrLogMessageProcessor);
        $logger->error('Token {token}', ['token' => $token]);
        $logger->error('Database failure', ['exception' => new QueryException(
            'sqlite', 'insert into users (password,email) values (?,?)',
            ['fictional-hash-secret', 'private@example.test'], new \RuntimeException('database failure'),
        )]);
        $record = json_encode($handler->getRecords());
        $this->assertStringNotContainsString($token, $record);
        $this->assertStringNotContainsString('fictional-password-value', $record);
        $this->assertStringNotContainsString('private%40example.test', $record);
        $this->assertStringNotContainsString('private@example.test', $record);
        $this->assertStringNotContainsString('fictional-hash-secret', $record);
        $this->assertStringNotContainsString('insert into users', $record);
        $this->assertStringContainsString('REDACTED', $record);
        $this->assertStringContainsString('RuntimeException', $record);
    }

    public function test_internal_invitation_error_hides_debug_output_and_logs_redacted_diagnostics(): void
    {
        config(['app.debug' => true, 'logging.default' => 'privacy-test', 'logging.channels.privacy-test' => [
            'driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [RedactInvitationSecrets::class],
        ]]);
        Route::middleware('web')->get('/owner-invitations/{token}/privacy-test', function (string $token) {
            throw new \RuntimeException('Internal error for '.$token.' '.request('email'));
        });
        $token = 'fictional-runtime-secret';
        $response = $this->get('/owner-invitations/'.$token.'/privacy-test?email=private%40example.test');
        $response->assertStatus(500)->assertDontSee($token)->assertDontSee('private@example.test')->assertDontSee('RuntimeException');
        $this->assertProtected($response);
        $records = Log::channel('privacy-test')->getLogger()->getHandlers()[0]->getRecords();
        $this->assertNotEmpty($records);
        $this->assertStringNotContainsString($token, json_encode($records));
        $this->assertStringNotContainsString('private@example.test', json_encode($records));
    }

    public function test_all_configured_channels_have_the_redaction_tap(): void
    {
        foreach (config('logging.channels') as $channel) {
            $this->assertContains(RedactInvitationSecrets::class, $channel['tap']);
        }
    }

    public function test_rate_limit_headers_survive_the_private_error_response(): void
    {
        Route::middleware('web')->get('/employee-invitations/{token}/limited-test', function () {
            throw new TooManyRequestsHttpException(30, 'Sensitive detail');
        });
        $response = $this->getJson('/employee-invitations/fictional-token/limited-test');
        $response->assertStatus(429)->assertHeader('Retry-After', '30')->assertDontSee('Sensitive detail');
        $this->assertProtected($response);
    }
}
