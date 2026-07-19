<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentCompanyController extends Controller
{
    public function __invoke(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);

        $validated = $request->validate([
            'company' => ['nullable', 'string', 'max:255'],
        ]);

        $slug = trim($validated['company'] ?? '');
        $company = $slug === ''
            ? null
            : Company::query()
                ->where('slug', $slug)
                ->where('is_active', true)
                ->firstOrFail();

        $currentCompany->set($company);

        return redirect()->route('dashboard');
    }
}
