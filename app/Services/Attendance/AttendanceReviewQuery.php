<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\PayPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AttendanceReviewQuery
{
    public function __construct(private PayrollShiftEvaluationResolver $evaluationResolver) {}

    /** @return Collection<int, PayrollShiftReview> */
    public function forPeriod(PayPeriod $payPeriod, ?int $uploadedFileId = null): Collection
    {
        $reviews = collect();
        $employees = Employee::withoutCompanyScope()
            ->where('company_id', $payPeriod->company_id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();
        $start = CarbonImmutable::parse($payPeriod->start_date);
        $end = CarbonImmutable::parse($payPeriod->end_date);

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            foreach ($employees as $employee) {
                $review = $this->evaluationResolver->review($payPeriod, $employee, $date);

                if ($review->analysis->overtimeCandidates->isEmpty()
                    && $review->analysis->deficits->isEmpty()) {
                    continue;
                }

                if ($uploadedFileId !== null && ! $review->occurrence->marks->contains(
                    fn ($mark): bool => $mark->uploaded_file_id === $uploadedFileId,
                )) {
                    continue;
                }

                $reviews->push($review);
            }
        }

        return $reviews;
    }
}
