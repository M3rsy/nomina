<?php

namespace App\Services\Attendance;

use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

final class GeneralWorkScheduleResolver
{
    public function resolve(int $companyId, CarbonInterface|string $date): WorkScheduleProfile
    {
        $date = CarbonImmutable::parse($date)->toDateString();
        $publications = WorkScheduleProfilePublication::withoutCompanyScope()
            ->with('profile')
            ->where('company_id', $companyId)
            ->where('profile_key', 'general')
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>', $date))
            ->orderBy('id')
            ->get();

        if ($publications->count() !== 1 || $publications->sole()->profile?->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'schedule_profile_id' => 'No existe una única jornada general vigente para la fecha indicada.',
            ]);
        }

        return $publications->sole()->profile;
    }
}
