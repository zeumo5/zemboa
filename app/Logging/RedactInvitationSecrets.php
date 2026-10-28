<?php

namespace App\Logging;

use App\Support\InvitationPrivacy;
use Illuminate\Database\QueryException;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

class RedactInvitationSecrets
{
    private array $recordSecrets = [];

    public function exceptionContext(\Throwable $exception): array
    {
        return $this->value($exception);
    }

    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(function (LogRecord $record): LogRecord {
            $this->recordSecrets = $this->secrets($record->context);
            try {
                return $record->with(
                    message: $this->text($record->message),
                    context: $this->value($record->context),
                    extra: $this->value($record->extra),
                );
            } finally {
                $this->recordSecrets = [];
            }
        });
    }

    private function secrets(array $context, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }
        $secrets = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match('/password|token|authorization|cookie|^email$/i', $key) && is_string($value) && $value !== '') {
                $secrets[] = $value;
            } elseif (is_array($value)) {
                $secrets = array_merge($secrets, $this->secrets($value, $depth + 1));
            }
        }

        return $secrets;
    }

    private function text(string $text): string
    {
        foreach ($this->recordSecrets as $secret) {
            $text = str_replace([$secret, rawurlencode($secret), urlencode($secret)], '[REDACTED]', $text);
        }
        if (app()->bound('request')) {
            $request = request();
            if (InvitationPrivacy::applies($request)) {
                foreach ([$request->route('token'), $request->input('token'), $request->input('_token'), $request->input('password'), $request->input('password_confirmation'), $request->input('email')] as $secret) {
                    if (is_string($secret) && $secret !== '') {
                        $text = str_replace([$secret, rawurlencode($secret), urlencode($secret)], '[REDACTED]', $text);
                    }
                }
            }
        }
        $text = preg_replace('~((?:owner|employee)-invitations/)[^/?\s\x22\x27<>]+~i', '$1[REDACTED]', $text);

        return preg_replace('~([?&](?:email|token|password|password_confirmation)=)[^&\s\x22\x27<>]*~i', '$1[REDACTED]', $text);
    }

    private function value(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[OMITTED]';
        }
        if ($value instanceof \Throwable) {
            $trace = [];
            foreach ($value->getTrace() as $frame) {
                $trace[] = $this->text(($frame['file'] ?? '[internal]').':'.($frame['line'] ?? 0).' '.($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''));
            }

            return ['class' => $value::class,
                'message' => $value instanceof QueryException
                    ? 'Database operation failed; SQL and bindings omitted.' : $this->text($value->getMessage()),
                'trace' => $trace];
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = is_string($key) && preg_match('/password|token|authorization|cookie|^email$/i', $key)
                    ? '[REDACTED]' : $this->value($item, $depth + 1);
            }

            return $result;
        }
        if (is_string($value)) {
            return $this->text($value);
        }

        return is_object($value) ? '[OBJECT OMITTED: '.$value::class.']' : $value;
    }
}
