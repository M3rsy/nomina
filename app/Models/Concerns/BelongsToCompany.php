<?php

namespace App\Models\Concerns;

use App\Services\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $companyId = app(CurrentCompany::class)->id();

            if ($companyId !== null) {
                $builder->where($builder->getModel()->getTable().'.company_id', $companyId);
            } elseif (auth()->check() && ! auth()->user()->hasRole('super_admin')) {
                $builder->whereRaw('1 = 0');
            }
        });
    }

    public static function withoutCompanyScope(): Builder
    {
        return static::withoutGlobalScope('company');
    }
}
