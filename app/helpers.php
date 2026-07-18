<?php

use App\Models\Company;
use App\Services\CurrentCompany;

if (! function_exists('current_company')) {
    function current_company(): ?Company
    {
        return app(CurrentCompany::class)->get();
    }
}

if (! function_exists('current_company_id')) {
    function current_company_id(): ?int
    {
        return app(CurrentCompany::class)->id();
    }
}
