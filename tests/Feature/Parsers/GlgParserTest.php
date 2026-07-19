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
    expect($first->row_number)->toBe(1);
    expect($first->source)->toBe('glg');
});

test('glg parser ignores blank lines and accepts seven column rows', function () use ($glgContents) {
    $parser = new GlgParser;
    $parsed = $parser->parse($glgContents);

    expect($parsed->records->contains(fn ($r) => $r->employee_external_id === 'TEST-002' && $r->event_at->toDateString() === '2026-01-06'))->toBeTrue();
});
