<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;

final class PublicShopping
{
    public static function allowed(): bool
    {
        return ! auth('web')->check();
    }

    public static function assertAllowed(): void
    {
        if (! self::allowed()) {
            throw new AuthorizationException(__('access.shopping_blocked'));
        }
    }
}
