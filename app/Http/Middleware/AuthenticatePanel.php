<?php

namespace App\Http\Middleware;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Like Filament's Authenticate, but instead of a bare 403 when a signed-in user opens a panel that
 * isn't theirs: send them to the panel they can use, or sign them out if they can use none
 * (deactivated login or shop).
 */
class AuthenticatePanel extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();
        $user = $guard->user();
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($user instanceof User && ! $user->canAccessPanel($panel)) {
            foreach (Filament::getPanels() as $other) {
                if ($other->getId() !== $panel->getId() && $user->canAccessPanel($other)) {
                    throw new HttpResponseException(redirect()->to($other->getUrl()));
                }
            }

            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw new HttpResponseException(redirect()->to($panel->getLoginUrl()));
        }

        parent::authenticate($request, $guards);
    }
}
