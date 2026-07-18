<?php

namespace App\Services\Parsers;

interface Parser
{
    public function parse(string $contents): ParsedFile;
}
