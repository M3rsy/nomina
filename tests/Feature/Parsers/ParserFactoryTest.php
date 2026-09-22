<?php

use App\Services\Parsers\AttlogParser;
use App\Services\Parsers\GlgParser;
use App\Services\Parsers\ParserFactory;
use App\Services\Parsers\UnsupportedFileException;

test('factory returns glg parser for txt files regardless of filename or extension case', function () {
    expect(ParserFactory::make('attendance.txt'))->toBeInstanceOf(GlgParser::class);
    expect(ParserFactory::make('monthly-report.TXT'))->toBeInstanceOf(GlgParser::class);
});

test('factory returns attlog parser for dat files regardless of filename or extension case', function () {
    expect(ParserFactory::make('attendance.dat'))->toBeInstanceOf(AttlogParser::class);
    expect(ParserFactory::make('monthly-report.DAT'))->toBeInstanceOf(AttlogParser::class);
});

test('factory throws for unsupported extensions', function () {
    expect(fn () => ParserFactory::make('report.pdf'))->toThrow(UnsupportedFileException::class);
    expect(fn () => ParserFactory::make('data.csv'))->toThrow(UnsupportedFileException::class);
});
