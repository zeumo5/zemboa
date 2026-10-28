<?php

namespace App\Support;

use Illuminate\Foundation\Application;
use Illuminate\Mail\Events\MessageSending;
use RuntimeException;
use Symfony\Component\Mime\Email;

final class DemoMail
{
    public static function configuration(string $mode = 'array'): array
    {
        if (! in_array($mode, ['array', 'mailpit'], true)) {
            throw new RuntimeException('Unsupported DEMO mail mode.');
        }

        return [
            'default' => $mode,
            'mailers' => [$mode => $mode === 'array' ? ['transport' => 'array'] : [
                'transport' => 'smtp', 'scheme' => 'smtp', 'host' => '127.0.0.1',
                'port' => 1025, 'url' => null, 'username' => null, 'password' => null,
                'timeout' => 5, 'auto_tls' => false,
            ]],
            'from' => ['address' => 'noreply@example.test', 'name' => 'ZEMBOA DEMO'],
        ];
    }

    public static function configure(Application $app, string $mode): void
    {
        $expected = self::configuration($mode);
        $app->make('config')->set('mail', $expected);
        $app->booted(function () use ($app, $expected): void {
            $app->make('events')->listen(MessageSending::class, function (MessageSending $event) use ($app, $expected): void {
                if (! $app->environment('demo') || $app->make('config')->get('mail') !== $expected) {
                    throw new RuntimeException('DEMO mail isolation required.');
                }
                self::assertAddresses($event->message);
            });
        });
    }

    public static function assertAddresses(Email $message): void
    {
        $recipients = [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()];
        $addresses = [...$recipients, ...$message->getFrom(), ...$message->getReplyTo()];
        foreach ([$message->getSender(), $message->getReturnPath()] as $address) {
            if ($address !== null) {
                $addresses[] = $address;
            }
        }
        if ($recipients === []) {
            throw new RuntimeException('DEMO mail requires a fictitious recipient.');
        }
        foreach ($addresses as $address) {
            if (strtolower(substr(strrchr($address->getAddress(), '@') ?: '', 1)) !== 'example.test') {
                throw new RuntimeException('DEMO mail only accepts example.test addresses.');
            }
        }
    }
}
