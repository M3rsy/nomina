<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\OvertimeDecision;
use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AttendanceDecisionAppender
{
    private const AUTHORIZATION_SETTING = 'nomina.attendance_compatible_supersession';

    /**
     * @template T of OvertimeDecision|AttendanceException
     *
     * @param  T|null  $previous
     * @param  Closure(): T  $write
     * @return T
     */
    public function append(
        OvertimeDecision|AttendanceException|null $previous,
        AttendanceSegment $current,
        Closure $write,
    ): OvertimeDecision|AttendanceException {
        if ($previous === null) {
            return $write();
        }

        [$decisionType, $keyColumn] = $this->decisionType($previous);
        if ($current->identity->matchesRecord($previous, $keyColumn)) {
            return $write();
        }

        $compatibleIdentity = collect($current->identities())
            ->skip(1)
            ->first(fn (AttendanceDecisionIdentity $identity): bool => $identity->matchesRecord($previous, $keyColumn));

        if ($compatibleIdentity === null) {
            throw new LogicException('Attendance supersession requires an exact canonical or compatible predecessor identity.');
        }

        $connection = $previous->getConnection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Legacy attendance supersession must run inside its payroll transaction.');
        }

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select(
                "select set_config('".self::AUTHORIZATION_SETTING."', ?, true)",
                [json_encode([
                    'decision_type' => $decisionType,
                    'parent_id' => $previous->getKey(),
                    'parent_key' => $compatibleIdentity->key,
                    'parent_fingerprint' => $compatibleIdentity->fingerprint,
                    'child_record_version' => $this->recordVersion($current),
                    'child_key' => $current->identity->key,
                    'child_fingerprint' => $current->identity->fingerprint,
                    'company_id' => $previous->company_id,
                    'pay_period_id' => $previous->pay_period_id,
                    'employee_id' => $previous->employee_id,
                    'work_date' => $previous->work_date->toDateString(),
                ], JSON_THROW_ON_ERROR)],
            );
        }

        $appended = $write();
        $this->assertExactAppend($appended, $previous, $current, $keyColumn);

        return $appended;
    }

    /** @return array{string, string} */
    private function decisionType(OvertimeDecision|AttendanceException $decision): array
    {
        return $decision instanceof OvertimeDecision
            ? ['overtime', 'candidate_key']
            : ['attendance_exception', 'deficit_key'];
    }

    private function recordVersion(AttendanceSegment $current): int
    {
        return in_array($current->kind, ['post_quota_overtime', 'daily_shortfall'], true) ? 2 : 1;
    }

    private function assertExactAppend(
        Model $appended,
        OvertimeDecision|AttendanceException $previous,
        AttendanceSegment $current,
        string $keyColumn,
    ): void {
        if ($appended::class !== $previous::class
            || $appended->record_version !== $this->recordVersion($current)
            || $appended->supersedes_id !== $previous->getKey()
            || ! $current->identity->matchesRecord($appended, $keyColumn)) {
            throw new LogicException('Appended attendance decision did not consume its exact authorized transition.');
        }
    }
}
