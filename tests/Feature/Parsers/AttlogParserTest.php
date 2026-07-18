<?php

use App\Services\Parsers\AttlogParser;

$attlogContents = file_get_contents('/home/m3rsy/GIt/proyecto-planilla/A8ME233160030_attlog (1) (1).dat');

test('attlog parser returns 38 records and trims leading spaces from EnNo', function () use ($attlogContents) {
    $parser = new AttlogParser;
    $parsed = $parser->parse($attlogContents);

    expect($parsed->records)->toHaveCount(38);

    $employees = $parsed->records->pluck('employee_external_id')->unique()->values()->toArray();
    sort($employees, SORT_STRING);
    expect($employees)->toBe(['12884', '13767', '44', '6419']);

    $first = $parsed->records->first();
    expect($first->employee_external_id)->toBe('13767');
});

test('attlog parser parses first row date time correctly', function () use ($attlogContents) {
    $parser = new AttlogParser;
    $parsed = $parser->parse($attlogContents);

    $first = $parsed->records->first();
    expect($first->event_at->toDateTimeString())->toBe('2026-01-26 05:21:38');
    expect($first->row_number)->toBe(1);
    expect($first->source)->toBe('attlog');
});

test('attlog parser stores trailing fixed columns as metadata', function () use ($attlogContents) {
    $parser = new AttlogParser;
    $parsed = $parser->parse($attlogContents);

    $first = $parsed->records->first();
    expect($first->metadata)->toBe(['1', '0', '1', '0']);
});
