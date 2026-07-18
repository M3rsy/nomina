<?php

use App\Services\Parsers\GlgParser;
use Carbon\Carbon;

$glgContents = file_get_contents('/home/m3rsy/GIt/proyecto-planilla/GLG_001 (1).TXT');

test('glg parser detects header and returns 34 records', function () use ($glgContents) {
    $parser = new GlgParser;
    $parsed = $parser->parse($glgContents);

    expect($parsed->records)->toHaveCount(34);

    $employees = $parsed->records->pluck('employee_external_id')->unique()->values()->toArray();
    sort($employees, SORT_STRING);
    expect($employees)->toBe(['1222', '12884', '13767', '44']);
});

test('glg parser parses first row date time correctly', function () use ($glgContents) {
    $parser = new GlgParser;
    $parsed = $parser->parse($glgContents);

    $first = $parsed->records->first();
    expect($first->employee_external_id)->toBe('13767');
    expect($first->event_at->toDateTimeString())->toBe('2026-01-19 14:53:50');
    expect($first->row_number)->toBe(1);
    expect($first->source)->toBe('glg');
});

test('glg parser ignores blank lines and accepts seven column rows', function () use ($glgContents) {
    $parser = new GlgParser;
    $parsed = $parser->parse($glgContents);

    expect($parsed->records->contains(fn ($r) => $r->employee_external_id === '12884' && $r->event_at->toDateString() === '2026-01-23'))->toBeTrue();
});
