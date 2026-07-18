<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionFreshAfterPasswordChange
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();
            $passwordChangedAt = $user->password_changed_at;

            if ($passwordChangedAt) {
                $loginAt = Session::get('login_at');

                if ($loginAt && Carbon::parse($loginAt)->lt($passwordChangedAt)) {
                    Auth::logout();
                    Session::invalidate();
                    Session::regenerateToken();

                    return redirect()->route('login')->with('error', 'Por favor inicie sesión de nuevo');
                }
            }
        }

        return $next($request);
    }
}
