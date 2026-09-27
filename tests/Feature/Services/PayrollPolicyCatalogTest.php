<?php

use App\Services\Payroll\Policy\DurationFirstPolicyDefinition;
use App\Services\Payroll\Policy\InCodePayrollPolicyCatalog;
use App\Services\Payroll\Policy\ScheduleOverlapPolicyDefinition;
use App\Services\Payroll\Policy\UnsupportedPayrollPolicy;

test('schedule overlap preserves legacy holiday and Sunday behavior', function () {
    $policy = (new InCodePayrollPolicyCatalog)->resolve('schedule-overlap-v1');
    assert($policy instanceof ScheduleOverlapPolicyDefinition);

    expect($policy)->toBeInstanceOf(ScheduleOverlapPolicyDefinition::class)
        ->and($policy->key)->toBe('schedule-overlap-v1')
        ->and($policy->calculationMode)->toBe('schedule_overlap')
        ->and($policy->rateBucketAt(600, true, 1, false))->toBe('extra100')
        ->and($policy->rateBucketAt(600, false, 0, false))->toBe('extra100')
        ->and($policy->rateBucketAt(120, false, 1, false))->toBe('extra75')
        ->and($policy->rateBucketAt(600, false, 1, false))->toBe('ordinary')
        ->and($policy->rateBucketAt(900, false, 1, false))->toBe('extra25')
        ->and($policy->rateBucketAt(1200, false, 1, false))->toBe('extra50')
        ->and($policy->rateBucketAt(600, false, 6, true))->toBe('extra25')
        ->and($policy->shouldCreateScheduledDeficit(15, true, 0))->toBeTrue()
        ->and($policy->shouldCreateOvertimeCandidate(15, true, 0))->toBeTrue()
        ->and($policy->shouldCreateFullDayAbsence(true, true, 480))->toBeFalse()
        ->and($policy->shouldCreateFullDayAbsence(false, true, 480))->toBeTrue()
        ->and($policy->definitionHash)
        ->toBe('0d24692c1022ff3cea6457170641c9ce00a3c7b57df9ced8df2d1c352c818488');
});

test('catalog exposes only immutable closed definitions', function () {
    $catalog = new InCodePayrollPolicyCatalog;
    $schedule = $catalog->resolve('schedule-overlap-v1');
    $duration = $catalog->resolve('duration-first-v2');
    assert($schedule instanceof ScheduleOverlapPolicyDefinition);
    assert($duration instanceof DurationFirstPolicyDefinition);

    expect(property_exists($schedule, 'parameters'))->toBeFalse()
        ->and((new ReflectionClass($schedule))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass($duration))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass($schedule))->getConstructor()?->getNumberOfParameters())->toBe(0)
        ->and((new ReflectionClass($duration))->getConstructor()?->getNumberOfParameters())->toBe(0)
        ->and((new ScheduleOverlapPolicyDefinition)->definitionHash)->toBe($schedule->definitionHash)
        ->and($duration->definitionHash)->not->toBe($schedule->definitionHash)
        ->and(fn () => $catalog->resolve('duration-first-v3'))
        ->toThrow(UnsupportedPayrollPolicy::class, 'Unsupported payroll policy [duration-first-v3].');
});

test('policies quantize complete elapsed minutes once', function () {
    $catalog = new InCodePayrollPolicyCatalog;

    expect($catalog->resolve('schedule-overlap-v1')->completeElapsedMinutes(3599))->toBe(59)
        ->and($catalog->resolve('duration-first-v2')->completeElapsedMinutes(3600))->toBe(60);
});

test('duration first applies its quota and shortfall gates', function () {
    $policy = (new InCodePayrollPolicyCatalog)->resolve('duration-first-v2');
    assert($policy instanceof DurationFirstPolicyDefinition);

    expect($policy)->toBeInstanceOf(DurationFirstPolicyDefinition::class)
        ->and($policy->key)->toBe('duration-first-v2')
        ->and($policy->calculationMode)->toBe('duration_first')
        ->and($policy->ordinaryMinutes(600, false, 1))->toBe(480)
        ->and($policy->ordinaryMinutes(300, false, 1))->toBe(300)
        ->and($policy->ordinaryMinutes(600, true, 1))->toBe(0)
        ->and($policy->overrideRateBucket(true, 1))->toBe('extra100')
        ->and($policy->overrideRateBucket(false, 0))->toBe('extra100')
        ->and($policy->extra100Minutes(600, true, 1))->toBe(600)
        ->and($policy->extra100Minutes(600, false, 0))->toBe(600)
        ->and($policy->shortfallMinutes(420, false, 1, true))->toBe(60)
        ->and($policy->shortfallMinutes(420, true, 1, true))->toBe(0)
        ->and($policy->shortfallMinutes(420, false, 0, true))->toBe(0)
        ->and($policy->shortfallMinutes(420, false, 1, false))->toBe(0);
});

test('duration first applies overtime bands and transfer exclusion', function () {
    $policy = (new InCodePayrollPolicyCatalog)->resolve('duration-first-v2');
    assert($policy instanceof DurationFirstPolicyDefinition);

    expect($policy)->toBeInstanceOf(DurationFirstPolicyDefinition::class)
        ->and($policy->shouldCreateOvertimeCandidate(480, false, 1))->toBeFalse()
        ->and($policy->shouldCreateOvertimeCandidate(481, false, 1))->toBeTrue()
        ->and($policy->shouldCreateOvertimeCandidate(600, true, 1))->toBeFalse()
        ->and($policy->shouldCreateOvertimeCandidate(600, false, 0))->toBeFalse()
        ->and($policy->excludedTransferMinutes(510, false, 1))->toBe(0)
        ->and($policy->excludedTransferMinutes(550, false, 1))->toBe(10)
        ->and($policy->excludedTransferMinutes(570, false, 1))->toBe(30)
        ->and($policy->excludedTransferMinutes(571, false, 1))->toBe(0)
        ->and($policy->recognizedOvertimeMinutes(550, false, 1))->toBe(60)
        ->and($policy->overtimeBucketAt(120))->toBe('extra75')
        ->and($policy->overtimeBucketAt(600))->toBe('extra25')
        ->and($policy->overtimeBucketAt(1200))->toBe('extra50');
});

test('duration first records entry variation only after scheduled start plus twenty minutes', function () {
    $policy = (new InCodePayrollPolicyCatalog)->resolve('duration-first-v2');
    assert($policy instanceof DurationFirstPolicyDefinition);

    expect($policy->shouldRecordEntryVariation(480, 20, false, 1))->toBeFalse()
        ->and($policy->shouldRecordEntryVariation(480, 21, false, 1))->toBeTrue()
        ->and($policy->shouldRecordEntryVariation(479, 21, false, 1))->toBeFalse()
        ->and($policy->shouldRecordEntryVariation(480, null, false, 1))->toBeFalse()
        ->and($policy->shouldRecordEntryVariation(480, 21, true, 1))->toBeFalse()
        ->and($policy->shouldRecordEntryVariation(480, 21, false, 0))->toBeFalse();
});
