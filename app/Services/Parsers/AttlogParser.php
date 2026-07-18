<?php

namespace App\Services\Parsers;

use Carbon\Carbon;

class AttlogParser implements Parser
{
    public function parse(string $contents): ParsedFile
    {
        $lines = $this->splitLines($contents);
        $records = collect();
        $rowNumber = 1;

        foreach ($lines as $line) {
            $line = trim($line, "\r\n");

            if ($line === '') {
                continue;
            }

            $columns = explode("\t", $line);

            if (count($columns) < 6) {
                continue;
            }

            $employeeExternalId = ltrim($columns[0]);
            $dateTime = $this->parseDateTime(trim($columns[1]));

            if ($dateTime === null || $employeeExternalId === '') {
                continue;
            }

            $records->push(new RawMarkPayload(
                employee_external_id: $employeeExternalId,
                event_at: $dateTime,
                raw_line: $line,
                row_number: $rowNumber,
                source: 'attlog',
                metadata: array_slice(array_map('trim', $columns), 2, 4),
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

    private function parseDateTime(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $value);
        } catch (\Exception) {
            return null;
        }
    }
}
