<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetActiveCompany
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! Auth::user()->hasRole('super_admin')) {
            abort(403);
        }

        $slug = $request->query('company') ?? $request->route('company');

        if ($slug) {
            $company = Company::where('slug', $slug)->first();

            if (! $company || ! $company->is_active) {
                abort(404);
            }

            app(CurrentCompany::class)->set($company);
        }

        return $next($request);
    }
}
