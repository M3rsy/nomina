<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;

test('a test can freeze Carbon clocks', function () {
    Carbon::setTestNow('2040-01-15 08:30:00');
    CarbonImmutable::setTestNow('2040-01-15 08:30:00');

    expect(Carbon::now()->toDateTimeString())->toBe('2040-01-15 08:30:00')
        ->and(CarbonImmutable::now()->toDateTimeString())->toBe('2040-01-15 08:30:00');
});

test('the next test observes real Carbon clocks', function () {
    expect(Carbon::hasTestNow())->toBeFalse()
        ->and(Carbon::now()->toDateTimeString())->not->toBe('2040-01-15 08:30:00')
        ->and(CarbonImmutable::hasTestNow())->toBeFalse()
        ->and(CarbonImmutable::now()->toDateTimeString())->not->toBe('2040-01-15 08:30:00');
})->depends('a test can freeze Carbon clocks');
