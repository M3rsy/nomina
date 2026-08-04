<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkScheduleProfileRetirer
{
    public function retireAndReassign(
        WorkScheduleProfile $source,
        WorkScheduleProfile $replacement,
        string $reason,
        User $actor,
    ): WorkScheduleProfile {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'retirementReason' => 'Ingresá el motivo para retirar la jornada.',
            ]);
        }

        return DB::transaction(function () use ($source, $replacement, $reason, $actor): WorkScheduleProfile {
            $profiles = WorkScheduleProfile::withoutCompanyScope()
                ->where('company_id', $source->company_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var WorkScheduleProfile|null $lockedSource */
            $lockedSource = $profiles->firstWhere('id', $source->id);
            /** @var WorkScheduleProfile|null $lockedReplacement */
            $lockedReplacement = $profiles->firstWhere('id', $replacement->id);

            if ($lockedSource === null || $lockedReplacement === null) {
                throw ValidationException::withMessages([
                    'replacementProfileId' => 'La jornada reemplazante debe pertenecer a la misma empresa.',
                ]);
            }

            if ($lockedSource->retired_at !== null) {
                if ($lockedSource->replacement_profile_id === $lockedReplacement->id) {
                    return $lockedSource;
                }

                throw ValidationException::withMessages([
                    'replacementProfileId' => 'La jornada ya fue retirada con otro reemplazo.',
                ]);
            }

            $this->validateProfiles($profiles, $lockedSource, $lockedReplacement);

            $today = CarbonImmutable::today();
            // Profile locks serialize schedule writes while this snapshot determines which periods lock first.
            $sourceAssignmentSnapshot = EmployeeScheduleAssignment::withoutCompanyScope()
                ->where('company_id', $lockedSource->company_id)
                ->where('work_schedule_profile_id', $lockedSource->id)
                ->where(function ($query) use ($today): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $today->toDateString());
                })
                ->orderBy('employee_id')
                ->orderBy('effective_from')
                ->orderBy('id')
                ->get(['employee_id', 'effective_from', 'effective_to']);

            $employeeIds = $sourceAssignmentSnapshot
                ->pluck('employee_id')
                ->unique()
                ->values();

            $lockedPeriods = $sourceAssignmentSnapshot->isEmpty()
                ? collect()
                : PayPeriod::withoutCompanyScope()
                    ->withTrashed()
                    ->where('company_id', $lockedSource->company_id)
                    ->where(function ($periods) use ($sourceAssignmentSnapshot, $today): void {
                        foreach ($sourceAssignmentSnapshot as $assignment) {
                            $affectedFrom = CarbonImmutable::instance($assignment->effective_from)
                                ->max($today)
                                ->subDay();
                            $affectedTo = $assignment->effective_to === null
                                ? null
                                : CarbonImmutable::instance($assignment->effective_to)->addDay();

                            $periods->orWhere(function ($period) use ($affectedFrom, $affectedTo): void {
                                $period->whereDate('end_date', '>=', $affectedFrom->toDateString())
                                    ->when(
                                        $affectedTo !== null,
                                        fn ($query) => $query->whereDate('start_date', '<=', $affectedTo->toDateString()),
                                    );
                            });
                        }
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'status']);

            if ($lockedPeriods->contains(fn (PayPeriod $period): bool => in_array(
                $period->status,
                PayPeriod::ATTENDANCE_LOCKED_STATUSES,
                true,
            ))) {
                throw ValidationException::withMessages([
                    'replacementProfileId' => 'La jornada no puede retirarse porque se superpone con un período de nómina bloqueado.',
                ]);
            }

            $lockedEmployeeIds = Employee::withoutCompanyScope()
                ->whereIn('id', $employeeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            $assignments = EmployeeScheduleAssignment::withoutCompanyScope()
                ->whereIn('employee_id', $lockedEmployeeIds)
                ->orderBy('employee_id')
                ->orderBy('effective_from')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $sourceAssignments = $assignments->filter(
                fn (EmployeeScheduleAssignment $assignment): bool => $assignment->work_schedule_profile_id === $lockedSource->id
                    && ($assignment->effective_to === null || $assignment->effective_to->gte($today)),
            );

            $assignments
                ->groupBy('employee_id')
                ->each(fn (Collection $employeeAssignments) => $this->reassignEmployee(
                    $employeeAssignments,
                    $lockedSource,
                    $lockedReplacement,
                    $today,
                    $reason,
                    $actor,
                ));

            $lockedSource->update([
                'is_active' => false,
                'retired_at' => now(),
                'retired_by' => $actor->id,
                'retirement_reason' => $reason,
                'replacement_profile_id' => $lockedReplacement->id,
            ]);

            return $lockedSource->fresh();
        });
    }

    /**
     * @param  Collection<int, WorkScheduleProfile>  $profiles
     */
    private function validateProfiles(
        Collection $profiles,
        WorkScheduleProfile $source,
        WorkScheduleProfile $replacement,
    ): void {
        if ($source->profile_key === 'general') {
            throw ValidationException::withMessages([
                'replacementProfileId' => 'La jornada general solo puede cambiar mediante una activación con fecha de vigencia.',
            ]);
        }

        if ($source->id === $replacement->id) {
            throw ValidationException::withMessages([
                'replacementProfileId' => 'Elegí una jornada reemplazante distinta.',
            ]);
        }

        if (! $source->is_active) {
            throw ValidationException::withMessages([
                'replacementProfileId' => 'La jornada seleccionada ya no está disponible.',
            ]);
        }

        if (! $replacement->is_active || $replacement->retired_at !== null) {
            throw ValidationException::withMessages([
                'replacementProfileId' => 'La jornada reemplazante debe estar disponible.',
            ]);
        }

        if ($profiles->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages([
                'replacementProfileId' => 'No se puede retirar la última jornada disponible.',
            ]);
        }
    }

    /**
     * @param  Collection<int, EmployeeScheduleAssignment>  $assignments
     */
    private function reassignEmployee(
        Collection $assignments,
        WorkScheduleProfile $source,
        WorkScheduleProfile $replacement,
        CarbonImmutable $today,
        string $reason,
        User $actor,
    ): void {
        $sourceAssignments = $assignments->filter(
            fn (EmployeeScheduleAssignment $assignment): bool => $assignment->work_schedule_profile_id === $source->id
                && ($assignment->effective_to === null || $assignment->effective_to->gte($today)),
        );

        foreach ($sourceAssignments as $assignment) {
            if ($assignment->effective_from->gte($today)) {
                $assignment->update([
                    'work_schedule_profile_id' => $replacement->id,
                    'assigned_by' => $actor->id,
                    'reason' => $reason,
                ]);

                continue;
            }

            $originalEffectiveTo = $assignment->effective_to?->toDateString();
            $assignment->update(['effective_to' => $today->subDay()->toDateString()]);

            $sameDayAssignment = $assignments->first(
                fn (EmployeeScheduleAssignment $candidate): bool => $candidate->effective_from->isSameDay($today),
            );

            if ($sameDayAssignment !== null) {
                continue;
            }

            EmployeeScheduleAssignment::withoutCompanyScope()->create([
                'company_id' => $assignment->company_id,
                'employee_id' => $assignment->employee_id,
                'work_schedule_profile_id' => $replacement->id,
                'effective_from' => $today->toDateString(),
                'effective_to' => $originalEffectiveTo,
                'assigned_by' => $actor->id,
                'reason' => $reason,
            ]);
        }
    }
}
