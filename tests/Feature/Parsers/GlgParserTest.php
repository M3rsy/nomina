<?php

use App\Services\Parsers\GlgParser;

$glgContents = file_get_contents(__DIR__.'/../../Fixtures/Attendance/GLG_minimal.txt');

test('glg parser detects header and returns two records', function () use ($glgContents) {
    $parser = new GlgParser;
    $parsed = $parser->parse($glgContents);

    expect($parsed->records)->toHaveCount(2);

    $employees = $parsed->records->pluck('employee_external_id')->unique()->values()->toArray();
    sort($employees, SORT_STRING);
    expect($employees)->toBe(['TEST-001', 'TEST-002']);
});

test('glg parser parses first row date time correctly', function () use ($glgContents) {
    $parser = new GlgParser;
    $parsed = $parser->parse($glgContents);

    $first = $parsed->records->first();
    expect($first->employee_external_id)->toBe('TEST-001');
    expect($first->event_at->toDateTimeString())->toBe('2026-01-05 08:15:00');
    expect($first->row_number)->toBe(3);
    expect($first->source)->toBe('glg');

    expect($parsed->records->pluck('row_number')->all())->toBe([3, 4]);
});

test('glg parser uses physical line numbers after header blank and malformed lines', function () {
    $contents = implode("\r\n", [
        "No\tDevice\tEmployee ID\tName\tMode\tStatus\tTimestamp",
        '',
        'malformed',
        "1\tDEVICE-01\tTEST-001\tSynthetic Employee 1\t1\t1\t01/05/2026 08:15:00",
        "2\tDEVICE-01\tTEST-002\tSynthetic Employee 2\t1\t1\t01/06/2026 17:30:00",
    ]);

    $parser = new GlgParser;
    $parsed = $parser->parse($contents);

    expect($parsed->records)->toHaveCount(2);
    expect($parsed->records->pluck('row_number')->all())->toBe([4, 5]);
});

test('glg parser ignores blank lines and accepts seven column rows', function () use ($glgContents) {
    $parser = new GlgParser;
    $parsed = $parser->parse($glgContents);

    expect($parsed->records->contains(fn ($r) => $r->employee_external_id === 'TEST-002' && $r->event_at->toDateString() === '2026-01-06'))->toBeTrue();
});
