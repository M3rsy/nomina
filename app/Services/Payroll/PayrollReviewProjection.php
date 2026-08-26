<?php

namespace App\Services\Payroll;

use App\Models\AttendanceException;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\PayrollReviewEntry;
use App\Models\RawMark;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\AttendanceSegment;
use App\Services\Attendance\PayrollPeriodReviewSnapshot;
use App\Services\Attendance\PayrollShiftReview;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

class PayrollReviewProjection
{
    public function __construct(private PayrollPeriodReviewSnapshot $snapshots) {}

    public function rebuild(PayPeriod $payPeriod): int
    {
        if (! Schema::hasTable('payroll_review_entries')) {
            return 0;
        }

        $generation = $this->generation($payPeriod);
        $snapshot = $this->snapshots->captureForPeriod($payPeriod);

        return DB::transaction(function () use ($payPeriod, $generation, $snapshot): int {
            PayrollReviewEntry::withoutCompanyScope()
                ->where('pay_period_id', $payPeriod->id)
                ->where('generation', $generation)
                ->delete();

            $rows = collect();
            $count = 0;
            $this->snapshots->forEachReview(
                $snapshot,
                function (PayrollShiftReview $review) use ($payPeriod, $generation, $rows, &$count): void {
                    foreach ($this->projectReview($payPeriod, $review, $generation) as $row) {
                        $rows->push($row);
                        $count++;
                        if ($rows->count() === 500) {
                            $this->upsertRows($rows);
                            $rows->splice(0);
                        }
                    }
                },
            );
            if ($rows->isNotEmpty()) {
                $this->upsertRows($rows);
            }

            PayrollReviewEntry::withoutCompanyScope()
                ->where('pay_period_id', $payPeriod->id)
                ->where('generation', '!=', $generation)
                ->delete();

            return $count;
        });
    }

    private function upsertRows(Collection $rows): void
    {
        PayrollReviewEntry::query()->upsert($rows->all(), ['pay_period_id', 'type', 'source_key', 'generation'], [
            'company_id', 'employee_id', 'work_date', 'status', 'source_fingerprint', 'occurred_at',
            'rate_ordinary_minutes', 'rate_extra25_minutes', 'rate_extra50_minutes', 'rate_extra75_minutes',
            'rate_extra100_minutes', 'payload', 'updated_at',
        ]);
    }

    public function freshGeneration(PayPeriod $payPeriod): ?string
    {
        if (! Schema::hasTable('payroll_review_entries')) {
            return null;
        }

        if (! PayrollReviewEntry::withoutCompanyScope()
            ->where('pay_period_id', $payPeriod->id)
            ->exists()) {
            return null;
        }

        $generation = $this->generation($payPeriod);

        return PayrollReviewEntry::withoutCompanyScope()
            ->where('pay_period_id', $payPeriod->id)
            ->where('generation', $generation)
            ->exists()
            ? $generation
            : null;
    }

    public function overtimeRows(PayPeriod $payPeriod, string $generation, array $filters, int $page): array
    {
        $query = $this->baseQuery($payPeriod, $generation, 'overtime_candidate')
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['date'] !== '', fn ($q) => $q->whereDate('work_date', $filters['date']))
            ->when($filters['search'] !== '', function ($q) use ($filters): void {
                $search = '%'.$filters['search'].'%';
                $q->whereHas('employee', fn ($employee) => $employee
                    ->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('external_id', 'like', $search));
            })
            ->when($filters['rate'] !== '', function ($q) use ($filters): void {
                $column = $this->rateMinutesColumn($filters['rate']);

                $column === null ? $q->whereRaw('1 = 0') : $q->where($column, '>', 0);
            })
            ->orderBy('work_date')
            ->orderBy('employee_id')
            ->orderBy('id');

        $pendingCount = (clone $query)->where('status', 'pending')->count();
        $rows = $query->with('employee')->paginate(25, ['*'], 'overtimePage', $page);
        $pageRows = $rows->getCollection()
            ->map(fn (PayrollReviewEntry $entry): array => $this->overtimeRowFromEntry($entry))
            ->values();
        $rows->setCollection($pageRows);

        return $this->overtimeRenderData($rows, $pendingCount);
    }

    public function deficitReviews(PayPeriod $payPeriod, string $generation): Collection
    {
        return $this->baseQuery($payPeriod, $generation, 'attendance_deficit')
            ->with(['employee'])
            ->orderBy('work_date')
            ->orderBy('employee_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (PayrollReviewEntry $entry): string => $entry->employee_id.'|'.$entry->work_date->toDateString())
            ->map(fn (Collection $entries): stdClass => $this->deficitReviewFromEntries($entries))
            ->values();
    }

    public function generation(PayPeriod $payPeriod): string
    {
        $payload = [
            'pay_period' => [$payPeriod->id, $payPeriod->updated_at?->toJSON(), $payPeriod->start_date?->toDateString(), $payPeriod->end_date?->toDateString()],
            'raw_marks' => $this->tableVersion(RawMark::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)),
            'overtime_decisions' => $this->tableVersion(OvertimeDecision::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)),
            'attendance_exceptions' => $this->tableVersion(AttendanceException::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)),
            'employees' => $this->tableVersion(Employee::withoutCompanyScope()->where('company_id', $payPeriod->company_id)),
            'profiles' => $this->tableVersion(WorkScheduleProfile::withoutCompanyScope()->where('company_id', $payPeriod->company_id)),
            'schedules' => $this->tableVersion(WorkSchedule::query()->where('company_id', $payPeriod->company_id)),
            'assignments' => $this->tableVersion(EmployeeScheduleAssignment::withoutCompanyScope()
                ->whereIn('employee_id', Employee::withoutCompanyScope()->where('company_id', $payPeriod->company_id)->select('id'))),
            'publications' => $this->tableVersion(WorkScheduleProfilePublication::withoutCompanyScope()->where('company_id', $payPeriod->company_id)),
            'holidays' => $this->tableVersion(Holiday::withoutCompanyScope()->where('company_id', $payPeriod->company_id)),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function baseQuery(PayPeriod $payPeriod, string $generation, string $type)
    {
        return PayrollReviewEntry::withoutCompanyScope()
            ->where('pay_period_id', $payPeriod->id)
            ->where('generation', $generation)
            ->where('type', $type);
    }

    private function projectReview(PayPeriod $payPeriod, PayrollShiftReview $review, string $generation): Collection
    {
        return $review->analysis->overtimeCandidates
            ->map(fn (AttendanceSegment $candidate): array => $this->row($payPeriod, $review, $candidate, 'overtime_candidate', $review->decisionFor($candidate), $generation))
            ->merge($review->analysis->deficits->map(
                fn (AttendanceSegment $deficit): array => $this->row($payPeriod, $review, $deficit, 'attendance_deficit', $review->exceptionFor($deficit), $generation),
            ));
    }

    private function row(PayPeriod $payPeriod, PayrollShiftReview $review, AttendanceSegment $segment, string $type, mixed $resolution, string $generation): array
    {
        $now = now();
        $status = $resolution?->decision ?? 'pending';

        return [
            'company_id' => $payPeriod->company_id,
            'pay_period_id' => $payPeriod->id,
            'employee_id' => $review->employee->id,
            'work_date' => $review->analysis->workDate->toDateString(),
            'type' => $type,
            'status' => $status,
            'source_key' => $segment->key,
            'source_fingerprint' => $segment->fingerprint,
            'generation' => $generation,
            'occurred_at' => $segment->start ?? $review->analysis->workDate,
            'rate_ordinary_minutes' => $segment->rateMinutes->ordinaryMinutes,
            'rate_extra25_minutes' => $segment->rateMinutes->extra25Minutes,
            'rate_extra50_minutes' => $segment->rateMinutes->extra50Minutes,
            'rate_extra75_minutes' => $segment->rateMinutes->extra75Minutes,
            'rate_extra100_minutes' => $segment->rateMinutes->extra100Minutes,
            'payload' => json_encode([
                'segment' => $this->segmentPayload($segment),
                'analysis' => [
                    'work_date' => $review->analysis->workDate->toDateString(),
                    'entry_at' => $review->analysis->entryAt?->toDateTimeString(),
                    'exit_at' => $review->analysis->exitAt?->toDateTimeString(),
                    'payroll_policy_key' => $review->analysis->payrollPolicyKey,
                    'excluded_transfer_minutes' => $review->analysis->excludedTransferMinutes,
                ],
                'occurrence' => [
                    'scheduled_start' => $review->occurrence->scheduledStart?->toDateTimeString(),
                    'scheduled_end' => $review->occurrence->scheduledEnd?->toDateTimeString(),
                ],
                'resolution' => $this->resolutionPayload($resolution),
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function segmentPayload(AttendanceSegment $segment): array
    {
        return [
            'kind' => $segment->kind,
            'key' => $segment->key,
            'fingerprint' => $segment->fingerprint,
            'start' => $segment->start?->toDateTimeString(),
            'end' => $segment->end?->toDateTimeString(),
            'minutes' => $segment->minutes,
            'rate_minutes' => [
                'ordinary' => $segment->rateMinutes->ordinaryMinutes,
                'extra25' => $segment->rateMinutes->extra25Minutes,
                'extra50' => $segment->rateMinutes->extra50Minutes,
                'extra75' => $segment->rateMinutes->extra75Minutes,
                'extra100' => $segment->rateMinutes->extra100Minutes,
            ],
        ];
    }

    private function resolutionPayload(mixed $resolution): ?array
    {
        if ($resolution === null) {
            return null;
        }

        return [
            'decision' => $resolution->decision,
            'reason' => $resolution->reason,
            'resolution_kind' => $resolution->resolution_kind ?? null,
            'approved_minutes' => $resolution->approved_minutes ?? null,
            'rejected_minutes' => $resolution->rejected_minutes ?? null,
            'decider_email' => $resolution->decider?->email,
            'created_at' => $resolution->created_at?->toDateTimeString(),
        ];
    }

    private function overtimeRowFromEntry(PayrollReviewEntry $entry): array
    {
        $review = $this->reviewShell($entry);
        $payload = $entry->payload;

        return [
            'review' => $review,
            'candidate' => $this->segmentShell($payload['segment']),
            'decision' => $this->resolutionShell($payload['resolution'] ?? null),
        ];
    }

    private function deficitReviewFromEntries(Collection $entries): ProjectedPayrollShiftReview
    {
        $first = $entries->first();
        $review = $this->reviewShell($first);
        $review->analysis->deficits = $entries->map(fn (PayrollReviewEntry $entry): stdClass => $this->segmentShell($entry->payload['segment']))->values();
        $review->resolutions = $entries
            ->mapWithKeys(fn (PayrollReviewEntry $entry): array => [
                $entry->source_key => $this->resolutionShell($entry->payload['resolution'] ?? null),
            ])->all();

        return $review;
    }

    private function reviewShell(PayrollReviewEntry $entry): ProjectedPayrollShiftReview
    {
        $payload = $entry->payload;
        $review = new ProjectedPayrollShiftReview;
        $review->employee = $entry->employee;
        $review->analysis = (object) [
            'workDate' => CarbonImmutable::parse($payload['analysis']['work_date']),
            'entryAt' => $payload['analysis']['entry_at'] ? CarbonImmutable::parse($payload['analysis']['entry_at']) : null,
            'exitAt' => $payload['analysis']['exit_at'] ? CarbonImmutable::parse($payload['analysis']['exit_at']) : null,
            'payrollPolicyKey' => $payload['analysis']['payroll_policy_key'],
            'excludedTransferMinutes' => (int) $payload['analysis']['excluded_transfer_minutes'],
            'deficits' => collect(),
        ];
        $review->occurrence = (object) [
            'scheduledStart' => $payload['occurrence']['scheduled_start'] ? CarbonImmutable::parse($payload['occurrence']['scheduled_start']) : null,
            'scheduledEnd' => $payload['occurrence']['scheduled_end'] ? CarbonImmutable::parse($payload['occurrence']['scheduled_end']) : null,
        ];

        return $review;
    }

    private function segmentShell(array $payload): stdClass
    {
        return (object) [
            'kind' => $payload['kind'],
            'key' => $payload['key'],
            'fingerprint' => $payload['fingerprint'],
            'start' => $payload['start'] ? CarbonImmutable::parse($payload['start']) : null,
            'end' => $payload['end'] ? CarbonImmutable::parse($payload['end']) : null,
            'minutes' => (int) $payload['minutes'],
            'rateMinutes' => new BandSplit(
                ordinaryMinutes: (int) ($payload['rate_minutes']['ordinary'] ?? 0),
                extra25Minutes: (int) ($payload['rate_minutes']['extra25'] ?? 0),
                extra50Minutes: (int) ($payload['rate_minutes']['extra50'] ?? 0),
                extra75Minutes: (int) ($payload['rate_minutes']['extra75'] ?? 0),
                extra100Minutes: (int) ($payload['rate_minutes']['extra100'] ?? 0),
            ),
        ];
    }

    private function resolutionShell(?array $payload): ?stdClass
    {
        if ($payload === null) {
            return null;
        }

        return (object) [
            'decision' => $payload['decision'],
            'reason' => $payload['reason'],
            'resolution_kind' => $payload['resolution_kind'],
            'approved_minutes' => $payload['approved_minutes'],
            'rejected_minutes' => $payload['rejected_minutes'],
            'created_at' => $payload['created_at'] ? CarbonImmutable::parse($payload['created_at']) : null,
            'decider' => (object) ['email' => $payload['decider_email']],
        ];
    }

    private function overtimeRenderData(LengthAwarePaginator $rows, int $pendingCount): array
    {
        $pageRows = $rows->getCollection();

        return [
            'rows' => $rows,
            'groups' => $pageRows
                ->groupBy(fn (array $row) => $row['review']->employee->id)
                ->map(fn (Collection $rows) => [
                    'employee' => $rows->first()['review']->employee,
                    'rows' => $rows,
                    'minutes' => $rows->sum(fn (array $row) => $row['candidate']->minutes),
                ])->values(),
            'pendingCount' => $pendingCount,
        ];
    }

    private function rateMinutesColumn(string $rate): ?string
    {
        return match ($rate) {
            'ordinary' => 'rate_ordinary_minutes',
            'extra25' => 'rate_extra25_minutes',
            'extra50' => 'rate_extra50_minutes',
            'extra75' => 'rate_extra75_minutes',
            'extra100' => 'rate_extra100_minutes',
            default => null,
        };
    }

    private function tableVersion($query): array
    {
        $model = $query->getModel();
        $rows = (clone $query)->orderBy($model->getKeyName())->get()
            ->map(fn ($row): array => $row->getAttributes())->all();

        return [
            'count' => count($rows),
            'fingerprint' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
        ];
    }
}
