<?php

namespace App\Services\Parsers;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class GlgParser implements Parser
{
    public function parse(string $contents): ParsedFile
    {
        $lines = $this->splitLines($contents);
        $records = collect();
        $rowNumber = 1;

        foreach ($lines as $line) {
            $line = trim($line, "\r\n");

            if ($this->isHeader($line)) {
                continue;
            }

            if ($line === '') {
                continue;
            }

            $columns = explode("\t", $line);

            if (count($columns) < 7) {
                continue;
            }

            $employeeExternalId = trim($columns[2]);
            $dateTime = $this->parseDateTime(trim($columns[6]));

            if ($dateTime === null) {
                continue;
            }

            $records->push(new RawMarkPayload(
                employee_external_id: $employeeExternalId,
                event_at: $dateTime,
                raw_line: $line,
                row_number: $rowNumber,
                source: 'glg',
            ));

            $rowNumber++;
        }

        return new ParsedFile(
            records: $records,
            totalLines: count($lines),
        );
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $contents): array
    {
        $normalized = str_replace("\r\n", "\n", $contents);
        $normalized = str_replace("\r", "\n", $normalized);

        return explode("\n", $normalized);
    }

    private function isHeader(string $line): bool
    {
        return str_starts_with($line, 'No\t') || str_starts_with($line, 'No ');
    }

    private function parseDateTime(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('m/d/Y H:i:s', $value);
        } catch (\Exception) {
            return null;
        }
    }
}
