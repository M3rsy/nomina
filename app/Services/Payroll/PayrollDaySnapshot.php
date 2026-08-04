<?php

namespace App\Services\Payroll;

use App\Models\AttendanceException;
use App\Models\AttendanceVariationAcknowledgement;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Services\Attendance\AttendanceSegment;
use App\Services\Attendance\PayrollShiftEvaluation;
use App\Services\Attendance\PayrollShiftReview;
use Carbon\CarbonInterface;

final readonly class PayrollDaySnapshot
{
    public string $hash;

    /** @param array<string, mixed> $data */
    public function __construct(public array $data)
    {
        $this->hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function capture(
        Employee $employee,
        PayrollShiftReview $review,
        PayrollShiftEvaluation $evaluation,
        int $calendarGeneration,
        string $rulesVersion,
    ): self {
        $occurrence = $review->occurrence;
        $analysis = $review->analysis;

        return new self([
            'schema_version' => 2,
            'work_date' => $evaluation->workDate->toDateString(),
            'employee' => [
                'id' => $employee->id,
                'external_id' => $employee->external_id,
                'name' => $employee->full_name,
            ],
            'rules_version' => $rulesVersion,
            'calendar_generation' => $calendarGeneration,
            'publication' => [
                'id' => $evaluation->publicationId,
                'payroll_policy_key' => $evaluation->payrollPolicyKey,
                'assignment_id' => $occurrence->assignment?->id,
                'profile_id' => $occurrence->assignment?->work_schedule_profile_id,
                'schedule_id' => $occurrence->schedule?->id,
            ],
            'attendance' => [
                'marks' => $occurrence->marks->map(fn ($mark): array => [
                    'id' => $mark->id,
                    'event_at' => self::dateTime($mark->event_at),
                    'status' => $mark->status,
                    'source' => $mark->source,
                    'employee_id' => $mark->employee_id,
                    'employee_external_id' => $mark->employee_external_id,
                    'revisions' => $mark->metadata['revisions'] ?? [],
                ])->values()->all(),
                'entry_at' => self::dateTime($evaluation->entryAt),
                'exit_at' => self::dateTime($evaluation->exitAt),
                'worked_minutes' => $evaluation->workedMinutes,
                'scheduled_minutes' => $evaluation->scheduledMinutes,
                'recognized_minutes' => $evaluation->recognizedMinutes,
                'detected_overtime_minutes' => $evaluation->detectedOvertimeMinutes,
                'approved_overtime_minutes' => $evaluation->approvedOvertimeMinutes,
                'excluded_transfer_minutes' => $analysis->excludedTransferMinutes,
            ],
            'payable_minutes' => self::rates($evaluation->payableRates),
            'shortfalls' => $analysis->deficits->sortBy('key')->map(function (AttendanceSegment $deficit) use ($review): array {
                $decision = $review->exceptionFor($deficit);

                return [
                    'state' => $decision?->decision ?? 'pending',
                    'reason' => $decision?->reason,
                    'fact' => self::segment($deficit),
                    'decision' => self::exception($decision),
                ];
            })->values()->all(),
            'overtime' => $analysis->overtimeCandidates->sortBy('key')->map(fn (AttendanceSegment $candidate): array => [
                'candidate' => self::segment($candidate),
                'decision' => self::overtimeDecision($review->decisionFor($candidate)),
            ])->values()->all(),
            'variations' => $analysis->variations->sortBy('key')->map(fn ($variation): array => [
                'key' => $variation->key,
                'fingerprint' => $variation->fingerprint,
                'kind' => $variation->kind,
                'entry_at' => self::dateTime($variation->entryAt),
                'acknowledgement' => self::acknowledgement($review->acknowledgementFor($variation)),
            ])->values()->all(),
        ]);
    }

    private static function segment(AttendanceSegment $segment): array
    {
        return [
            'key' => $segment->key,
            'fingerprint' => $segment->fingerprint,
            'kind' => $segment->kind,
            'starts_at' => self::dateTime($segment->start),
            'ends_at' => self::dateTime($segment->end),
            'minutes' => $segment->minutes,
            'rate_minutes' => self::rates($segment->rateMinutes),
        ];
    }

    private static function exception(?AttendanceException $decision): ?array
    {
        return $decision === null ? null : [
            'id' => $decision->id, 'record_version' => $decision->record_version,
            'deficit_key' => $decision->deficit_key, 'fingerprint' => $decision->fingerprint,
            'segment_kind' => $decision->segment_kind,
            'starts_at' => self::dateTime($decision->starts_at), 'ends_at' => self::dateTime($decision->ends_at),
            'minutes' => $decision->minutes, 'rate_minutes' => $decision->rate_minutes,
            'decision' => $decision->decision, 'reason' => $decision->reason,
            'decided_by' => $decision->decided_by, 'supersedes_id' => $decision->supersedes_id,
            'created_at' => self::dateTime($decision->created_at),
        ];
    }

    private static function overtimeDecision(?OvertimeDecision $decision): ?array
    {
        if ($decision === null) {
            return null;
        }

        $fields = [
            'id', 'record_version', 'candidate_key', 'fingerprint', 'segment_kind', 'minutes',
            'rate_minutes', 'decision', 'reason', 'decided_by', 'supersedes_id', 'batch_item_id',
            'resolution_kind', 'approved_minutes', 'rejected_minutes', 'rejected_before_minutes',
            'rejected_after_minutes', 'approved_rate_minutes', 'rejected_rate_minutes', 'resolution_hash',
        ];
        $data = collect($decision->only($fields))->all();

        foreach (['starts_at', 'ends_at', 'approved_starts_at', 'approved_ends_at',
            'rejected_before_starts_at', 'rejected_before_ends_at', 'rejected_after_starts_at',
            'rejected_after_ends_at', 'created_at'] as $field) {
            $data[$field] = self::dateTime($decision->{$field});
        }

        return $data;
    }

    private static function acknowledgement(?AttendanceVariationAcknowledgement $acknowledgement): ?array
    {
        return $acknowledgement === null ? null : [
            'id' => $acknowledgement->id, 'record_version' => $acknowledgement->record_version,
            'variation_key' => $acknowledgement->variation_key, 'fingerprint' => $acknowledgement->fingerprint,
            'variation_kind' => $acknowledgement->variation_kind,
            'entry_at' => self::dateTime($acknowledgement->entry_at),
            'reason' => $acknowledgement->reason, 'acknowledged_by' => $acknowledgement->acknowledged_by,
            'created_at' => self::dateTime($acknowledgement->created_at),
        ];
    }

    private static function rates(BandSplit $rates): array
    {
        return [
            'ordinary' => $rates->ordinaryMinutes, 'extra25' => $rates->extra25Minutes,
            'extra50' => $rates->extra50Minutes, 'extra75' => $rates->extra75Minutes,
            'extra100' => $rates->extra100Minutes,
        ];
    }

    private static function dateTime(?CarbonInterface $value): ?string
    {
        return $value?->toDateTimeString();
    }
}
