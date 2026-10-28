<?php

namespace Tests\Feature;

use App\Support\DemoMail;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class DemoMailTest extends TestCase
{
    public function test_default_is_array_and_smtp_has_no_external_fallback(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('array', DemoMail::configuration()['default']);
        $config = DemoMail::configuration('mailpit');
        $this->assertSame(['mailpit'], array_keys($config['mailers']));
        $this->assertSame('127.0.0.1', $config['mailers']['mailpit']['host']);
        $this->assertSame(1025, $config['mailers']['mailpit']['port']);
        $this->assertNull($config['mailers']['mailpit']['url']);
        $this->assertNull($config['mailers']['mailpit']['password']);
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        DemoMail::configuration('smtp');
    }

    public function test_fictitious_addresses_are_accepted(): void
    {
        DemoMail::assertAddresses((new Email)->from('noreply@example.test')->to('technical@example.test'));
        $this->addToAssertionCount(1);
    }

    public function test_hidden_external_recipient_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        DemoMail::assertAddresses((new Email)->from('noreply@example.test')->to('technical@example.test')->bcc('blocked@invalid.example'));
    }

    public function test_mail_cannot_send_outside_demo(): void
    {
        DemoMail::configure($this->app, 'array');
        $this->expectException(RuntimeException::class);
        $this->app->make('mailer')->raw('Technical test', fn ($mail) => $mail->to('technical@example.test'));
    }

    public function test_demo_array_sends_only_fictitious_mail(): void
    {
        $this->app->detectEnvironment(fn () => 'demo');
        DemoMail::configure($this->app, 'array');
        $sent = $this->app->make('mailer')->raw('Technical test', fn ($mail) => $mail->to('technical@example.test'));
        $this->assertNotNull($sent);
    }

    public function test_changed_configuration_is_rejected_before_send(): void
    {
        $this->app->detectEnvironment(fn () => 'demo');
        DemoMail::configure($this->app, 'array');
        config(['mail.from.name' => 'Changed']);
        $this->expectException(RuntimeException::class);
        $this->app->make('mailer')->raw('Technical test', fn ($mail) => $mail->to('technical@example.test'));
    }
}
