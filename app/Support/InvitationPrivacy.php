<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvitationPrivacy
{
    public static function applies(Request $request): bool
    {
        return $request->is('owner-invitations/*', 'employee-invitations/*');
    }

    public static function protect(Response $response, Request $request): Response
    {
        if (self::applies($request)) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
