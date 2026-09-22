<?php

namespace App\Services\Parsers;

class ParserFactory
{
    public static function make(string $filename): Parser
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($extension === 'txt') {
            return new GlgParser;
        }

        if ($extension === 'dat') {
            return new AttlogParser;
        }

        throw new UnsupportedFileException("Unsupported file type: {$filename}");
    }
}
