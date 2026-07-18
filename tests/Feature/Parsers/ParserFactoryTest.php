<?php

use App\Services\Parsers\AttlogParser;
use App\Services\Parsers\GlgParser;
use App\Services\Parsers\ParserFactory;
use App\Services\Parsers\UnsupportedFileException;

test('factory returns glg parser for txt files starting with GLG', function () {
    expect(ParserFactory::make('GLG_001.TXT'))->toBeInstanceOf(GlgParser::class);
    expect(ParserFactory::make('glg_january.txt'))->toBeInstanceOf(GlgParser::class);
});

test('factory returns attlog parser for dat files', function () {
    expect(ParserFactory::make('attlog.dat'))->toBeInstanceOf(AttlogParser::class);
    expect(ParserFactory::make('A8ME233160030_attlog.dat'))->toBeInstanceOf(AttlogParser::class);
});

test('factory throws for unsupported extensions', function () {
    expect(fn () => ParserFactory::make('report.pdf'))->toThrow(UnsupportedFileException::class);
    expect(fn () => ParserFactory::make('data.csv'))->toThrow(UnsupportedFileException::class);
});
