<?php

use App\Services\Payroll\BandSplitter;
use Carbon\Carbon;

beforeEach(function () {
    $this->splitter = new BandSplitter;
});

test('split 06:00 to 14:00 yields eight ordinary hours', function () {
    $split = $this->splitter->split(Carbon::parse('2026-10-07 06:00:00'), Carbon::parse('2026-10-07 14:00:00'));

    expect($split->ordinaryHours())->toEqual(8.0)
        ->and($split->extra25Hours())->toEqual(0.0)
        ->and($split->extra50Hours())->toEqual(0.0)
        ->and($split->extra75Hours())->toEqual(0.0)
        ->and($split->totalHours())->toEqual(8.0);
});

test('split 14:00 to 18:00 yields four extra 25 hours', function () {
    $split = $this->splitter->split(Carbon::parse('2026-10-07 14:00:00'), Carbon::parse('2026-10-07 18:00:00'));

    expect($split->ordinaryHours())->toEqual(0.0)
        ->and($split->extra25Hours())->toEqual(4.0)
        ->and($split->extra50Hours())->toEqual(0.0)
        ->and($split->extra75Hours())->toEqual(0.0);
});

test('split 18:00 to 23:00 yields five extra 50 hours', function () {
    $split = $this->splitter->split(Carbon::parse('2026-10-07 18:00:00'), Carbon::parse('2026-10-07 23:00:00'));

    expect($split->ordinaryHours())->toEqual(0.0)
        ->and($split->extra25Hours())->toEqual(0.0)
        ->and($split->extra50Hours())->toEqual(5.0)
        ->and($split->extra75Hours())->toEqual(0.0);
});

test('split 00:00 to 06:00 yields six extra 75 hours', function () {
    $split = $this->splitter->split(Carbon::parse('2026-10-07 00:00:00'), Carbon::parse('2026-10-07 06:00:00'));

    expect($split->ordinaryHours())->toEqual(0.0)
        ->and($split->extra25Hours())->toEqual(0.0)
        ->and($split->extra50Hours())->toEqual(0.0)
        ->and($split->extra75Hours())->toEqual(6.0);
});

test('split 05:14 to 17:27 matches user example decimals', function () {
    $split = $this->splitter->split(Carbon::parse('2026-10-07 05:14:00'), Carbon::parse('2026-10-07 17:27:00'));

    // 05:14-06:00 = 46 minutes = 0.7666...h
    expect($split->extra75Hours())->toBeFloat()->toBeBetween(0.765, 0.767)
        ->and($split->ordinaryHours())->toEqual(8.0)
        // 14:00-17:27 = 207 minutes = 3.45h
        ->and($split->extra25Hours())->toEqual(3.45)
        ->and($split->extra50Hours())->toEqual(0.0);
});

test('split crossing midnight 22:00 to 02:00 splits 50 and 75 bands', function () {
    $split = $this->splitter->split(Carbon::parse('2026-10-07 22:00:00'), Carbon::parse('2026-10-08 02:00:00'));

    expect($split->ordinaryHours())->toEqual(0.0)
        ->and($split->extra25Hours())->toEqual(0.0)
        // 22:00-00:00 = 2h extra 50%
        ->and($split->extra50Hours())->toEqual(2.0)
        // 00:00-02:00 = 2h extra 75%
        ->and($split->extra75Hours())->toEqual(2.0);
});

test('split returns zero for equal or reversed boundaries', function () {
    $split = $this->splitter->split(Carbon::parse('2026-10-07 08:00:00'), Carbon::parse('2026-10-07 08:00:00'));

    expect($split->totalHours())->toEqual(0.0);
});

test('split accepts custom 100% overtime band', function () {
    $bands = [
        ['start' => 0, 'end' => 480, 'bucket' => 'ordinary'],
        ['start' => 480, 'end' => 1440, 'bucket' => 'extra100'],
    ];

    $split = $this->splitter->split(Carbon::parse('2026-10-07 06:00:00'), Carbon::parse('2026-10-07 10:00:00'), $bands);

    expect($split->ordinaryHours())->toEqual(2.0)
        ->and($split->extra100Hours())->toEqual(2.0)
        ->and($split->extra25Hours())->toEqual(0.0)
        ->and($split->extra50Hours())->toEqual(0.0)
        ->and($split->extra75Hours())->toEqual(0.0)
        ->and($split->totalHours())->toEqual(4.0);
});

test('split supports wrapped bands that reach into next day', function () {
    $bands = [
        ['start' => 1080, 'end' => 60, 'bucket' => 'extra50'],
        ['start' => 60, 'end' => 360, 'bucket' => 'extra25'],
    ];

    $split = $this->splitter->split(Carbon::parse('2026-10-07 22:00:00'), Carbon::parse('2026-10-08 04:00:00'), $bands);

    expect($split->extra50Hours())->toBe(2.0)
        ->and($split->extra25Hours())->toBe(3.0);
});
