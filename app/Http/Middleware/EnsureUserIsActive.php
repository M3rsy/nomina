<?php

namespace App\Http\Middleware;

use App\Services\AccountAccess;
use App\Services\DatabaseSessionRevoker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        $user = Auth::user();
        $reason = $user ? app(AccountAccess::class)->denialReason($user) : null;

        if ($reason !== null) {
            $userId = $user->id;
            Auth::logout();
            app(DatabaseSessionRevoker::class)->revokeUser($userId);
            Session::invalidate();
            Log::warning('Account access denied', [
                'event' => 'account_access_denied', 'reason' => $reason, 'user_id' => $userId,
            ]);

            return redirect()->route('login')->with('error', AccountAccess::USER_MESSAGE);
        }

        return $next($request);
    }
}
