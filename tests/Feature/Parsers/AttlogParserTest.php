<?php

use App\Services\Parsers\AttlogParser;

$attlogContents = file_get_contents(__DIR__.'/../../Fixtures/Attendance/ATTLOG_minimal.dat');

test('attlog parser returns two records and trims leading spaces from EnNo', function () use ($attlogContents) {
    $parser = new AttlogParser;
    $parsed = $parser->parse($attlogContents);

    expect($parsed->records)->toHaveCount(2);

    $employees = $parsed->records->pluck('employee_external_id')->unique()->values()->toArray();
    sort($employees, SORT_STRING);
    expect($employees)->toBe(['TEST-001', 'TEST-002']);

    $first = $parsed->records->first();
    expect($first->employee_external_id)->toBe('TEST-001');
});

test('attlog parser parses first row date time correctly', function () use ($attlogContents) {
    $parser = new AttlogParser;
    $parsed = $parser->parse($attlogContents);

    $first = $parsed->records->first();
    expect($first->event_at->toDateTimeString())->toBe('2026-01-05 08:15:00');
    expect($first->row_number)->toBe(1);
    expect($first->source)->toBe('attlog');
});

test('attlog parser stores trailing fixed columns as metadata', function () use ($attlogContents) {
    $parser = new AttlogParser;
    $parsed = $parser->parse($attlogContents);

    $first = $parsed->records->first();
    expect($first->metadata)->toBe(['1', '0', '1', '0']);
});

test('attlog parser uses physical line numbers after blank malformed and invalid date lines', function () {
    $contents = implode("\r\n", [
        '',
        'malformed',
        "TEST-001\t2026-01-05 08:15:00\t1\t0\t1\t0",
        "TEST-002\tnot-a-date\t0\t1\t0\t1",
        "TEST-003\t2026-01-06 17:30:00\t0\t1\t0\t1",
    ]);

    $parser = new AttlogParser;
    $parsed = $parser->parse($contents);

    expect($parsed->records)->toHaveCount(2);
    expect($parsed->records->pluck('row_number')->all())->toBe([3, 5]);
});
