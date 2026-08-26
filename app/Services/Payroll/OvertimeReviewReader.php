<?php

namespace App\Services\Payroll;

use App\Models\PayPeriod;
use App\Services\Attendance\AttendanceReviewQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class OvertimeReviewReader
{
    public function __construct(
        private AttendanceReviewQuery $reviews,
        private PayrollReviewProjection $projection,
    ) {}

    /**
     * @param  array{search:string,status:string,date:string,rate:string}  $filters
     * @return array{rows:LengthAwarePaginator,groups:Collection,pendingCount:int}
     */
    public function forPeriod(PayPeriod $period, ?int $uploadedFileId, array $filters, int $page): array
    {
        $generation = $uploadedFileId === null ? $this->projection->freshGeneration($period) : null;

        if ($generation !== null) {
            return $this->projection->overtimeRows($period, $generation, $filters, $page);
        }

        return $this->render($this->filteredRows(
            $this->reviews->forPeriod($period, $uploadedFileId),
            $filters,
        ), $page);
    }

    /**
     * @param  array{search:string,status:string,date:string,rate:string}  $filters
     * @return Collection<string, array{employee_id:int,work_date:string,candidate_key:string,fingerprint:string}>
     */
    public function pendingTargetsForPeriod(PayPeriod $period, ?int $uploadedFileId, array $filters, ?int $page = null): Collection
    {
        $rows = $this->filteredRows($this->reviews->forPeriod($period, $uploadedFileId), $filters);
        if ($page !== null) {
            $rows = $rows->forPage($page, 25)->values();
        }

        return $rows->filter(fn (array $row): bool => $row['decision'] === null)
            ->mapWithKeys(function (array $row): array {
                $target = [
                    'employee_id' => $row['review']->employee->id,
                    'work_date' => $row['review']->analysis->workDate->toDateString(),
                    'candidate_key' => $row['candidate']->key,
                    'fingerprint' => $row['candidate']->fingerprint,
                ];

                return [implode('|', array_slice($target, 0, 3)) => $target];
            });
    }

    private function filteredRows(Collection $reviews, array $filters): Collection
    {
        return $reviews
            ->flatMap(fn ($review) => $review->analysis->overtimeCandidates->map(fn ($candidate) => [
                'review' => $review,
                'candidate' => $candidate,
                'decision' => $review->decisionFor($candidate),
            ]))
            ->filter(function (array $row) use ($filters): bool {
                $review = $row['review'];
                $candidate = $row['candidate'];
                $employee = mb_strtolower($review->employee->full_name.' '.$review->employee->external_id);
                $rateMinutes = match ($filters['rate']) {
                    'ordinary' => $candidate->rateMinutes->ordinaryMinutes,
                    'extra25' => $candidate->rateMinutes->extra25Minutes,
                    'extra50' => $candidate->rateMinutes->extra50Minutes,
                    'extra75' => $candidate->rateMinutes->extra75Minutes,
                    'extra100' => $candidate->rateMinutes->extra100Minutes,
                    default => 1,
                };

                return ($filters['status'] === 'all' || ($row['decision']?->decision ?? 'pending') === $filters['status'])
                    && ($filters['search'] === '' || str_contains($employee, $filters['search']))
                    && ($filters['date'] === '' || $review->analysis->workDate->toDateString() === $filters['date'])
                    && $rateMinutes > 0;
            })
            ->values();
    }

    private function render(Collection $filteredRows, int $page): array
    {
        $pageRows = $filteredRows->forPage($page, 25)->values();

        return [
            'rows' => new LengthAwarePaginator($pageRows, $filteredRows->count(), 25, $page, [
                'path' => request()->url(),
                'pageName' => 'overtimePage',
            ]),
            'groups' => $pageRows->groupBy(fn (array $row) => $row['review']->employee->id)
                ->map(fn (Collection $rows) => [
                    'employee' => $rows->first()['review']->employee,
                    'rows' => $rows,
                    'minutes' => $rows->sum(fn (array $row) => $row['candidate']->minutes),
                ])->values(),
            'pendingCount' => $filteredRows->where('decision', null)->count(),
        ];
    }
}
