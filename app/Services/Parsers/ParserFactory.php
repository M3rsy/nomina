<?php

namespace App\Services\Parsers;

use Illuminate\Support\Str;

class ParserFactory
{
    public static function make(string $filename): Parser
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $basename = strtoupper(pathinfo($filename, PATHINFO_BASENAME));

        if ($extension === 'txt' && str_starts_with($basename, 'GLG')) {
            return new GlgParser;
        }

        if ($extension === 'dat') {
            return new AttlogParser;
        }

        throw new UnsupportedFileException("Unsupported file type: {$filename}");
    }
}
