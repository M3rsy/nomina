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
