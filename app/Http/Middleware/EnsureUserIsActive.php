<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && ! Auth::user()->is_active) {
            $userId = Auth::id();

            Auth::logout();
            DB::table('sessions')->where('user_id', $userId)->delete();
            Session::flush();

            return redirect()->route('login')->with('error', 'Cuenta desactivada');
        }

        return $next($request);
    }
}
