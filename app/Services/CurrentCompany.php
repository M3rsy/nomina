<?php

namespace App\Services;

use App\Models\Company;

class CurrentCompany
{
    protected ?Company $company = null;

    public function __construct()
    {
        $this->resolve();
    }

    public function resolve(): ?Company
    {
        if ($this->company !== null) {
            return $this->company;
        }

        if (auth()->check() && auth()->user()->hasRole('super_admin')) {
            $sessionId = session('active_company_id');
            if ($sessionId) {
                return $this->company = Company::find($sessionId);
            }

            return null;
        }

        if (auth()->check()) {
            $user = auth()->user();
            if ($user->company_id) {
                return $this->company = Company::find($user->company_id);
            }
        }

        return null;
    }

    public function get(): ?Company
    {
        return $this->resolve();
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    public function set(?Company $company): void
    {
        $this->company = $company;

        if ($company) {
            session(['active_company_id' => $company->id]);
        } else {
            session()->forget('active_company_id');
        }
    }
}
