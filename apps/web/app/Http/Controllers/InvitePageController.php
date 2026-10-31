<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class InvitePageController extends Controller
{
    /**
     * Invite links are shared as /join/{token}. Only the token is handed to the
     * page; the group preview is fetched from the API so revoked and expired
     * links surface the same validation message everywhere.
     */
    public function __invoke(string $token): Response
    {
        return Inertia::render('Join', [
            'token' => $token,
        ]);
    }
}
