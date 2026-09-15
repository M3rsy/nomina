<?php

namespace App\Services\Payroll;

use App\Models\PayPeriod;
use App\Models\PayrollResult;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds an Excel report that imitates the historical attendance reference
 * files while only reading already stored PayrollResult rows. The report is
 * fully regenerable and never reprocesses RawMarks.
 */
class PayrollExcelExporter
{
    /** @var array<string, int> */
    private const COLUMN_WIDTHS = [
        'A' => 12,
        'B' => 18,
        'C' => 30,
        'D' => 24,
        'E' => 22,
        'F' => 22,
        'G' => 16,
        'H' => 18,
        'I' => 16,
        'J' => 16,
        'K' => 16,
        'L' => 17,
        'M' => 15,
    ];

    /** @var array<string, int> */
    private const AUDIT_COLUMN_WIDTHS = [
        'A' => 18, 'B' => 18, 'C' => 30, 'D' => 24, 'E' => 15, 'F' => 13,
        'G' => 22, 'H' => 22, 'I' => 18, 'J' => 45, 'K' => 45, 'L' => 18,
        'M' => 16, 'N' => 16, 'O' => 16, 'P' => 17, 'Q' => 16, 'R' => 18,
        'S' => 30, 'T' => 45, 'U' => 45, 'V' => 45, 'W' => 45, 'X' => 45,
        'Y' => 20, 'Z' => 22, 'AA' => 20, 'AB' => 18, 'AC' => 45,
    ];

    private const DATE_FORMAT = 'yyyy-mm-dd h:mm AM/PM';

    private const DECIMAL_HOURS_FORMAT = '#,##0.00';

    public function __construct(private ?PayrollReportingRowAdapter $rowAdapter = null)
    {
        $this->rowAdapter ??= new PayrollReportingRowAdapter;
    }

    public function export(PayPeriod $payPeriod): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Asistencia');
        $auditSheet = $spreadsheet->createSheet();
        $auditSheet->setTitle('Auditoría');

        $results = PayrollResult::withoutCompanyScope()
            ->where('pay_period_id', $payPeriod->id)
            ->orderBy('employee_id')
            ->orderBy('date')
            ->get();

        $this->applyColumnWidths($sheet);
        $this->writeTitleRows($sheet, $payPeriod);
        $this->writeHeaderRow($sheet);
        $this->writeDataRows($sheet, $results);
        $this->applyHeaderStyle($sheet);

        $this->applyColumnWidths($auditSheet, self::AUDIT_COLUMN_WIDTHS);
        $this->writeAuditRows($auditSheet, $payPeriod, $results);
        $spreadsheet->setActiveSheetIndex(0);

        return TemporaryXlsxFile::write('payroll_export_', function (string $path) use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save($path);
        });
    }

    public function filename(PayPeriod $payPeriod): string
    {
        $start = $payPeriod->start_date->format('Ymd');
        $end = $payPeriod->end_date->format('Ymd');

        return "Asistencia {$start} hasta {$end}.xlsx";
    }

    /** @param array<string, int> $widths */
    private function applyColumnWidths(Worksheet $sheet, array $widths = self::COLUMN_WIDTHS): void
    {
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function writeTitleRows(Worksheet $sheet, PayPeriod $payPeriod): void
    {
        $lastColumn = 'M';

        $start = $payPeriod->start_date;
        $end = $payPeriod->end_date;
        $monthName = $this->spanishMonthName((int) $end->format('n'));
        $year = $end->format('Y');
        $title = sprintf(
            'REPORTE DEL %s AL %s %s %s',
            $start->format('d'),
            $end->format('d'),
            strtoupper($monthName),
            $year
        );

        $sheet->setCellValue('A2', $title);
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $weekLabel = sprintf(
            'SEMANA N %s %s',
            strtoupper($monthName),
            $year
        );

        $sheet->setCellValue('A3', $weekLabel);
        $sheet->mergeCells("A3:{$lastColumn}3");
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function writeHeaderRow(Worksheet $sheet): void
    {
        $headers = [
            'A5' => 'Código de empleado',
            'B5' => 'Código de pago',
            'C5' => 'NOMBRE',
            'D5' => 'Cargo',
            'E5' => 'Entrada',
            'F5' => 'Salida',
            'G5' => 'Cantidad Horas',
            'H5' => 'Horas Ordinarias',
            'I5' => 'Horas Ext 25%',
            'J5' => 'Horas Ext 50%',
            'K5' => 'Horas Ext 75%',
            'L5' => 'Horas Ext 100%',
            'M5' => 'Fecha laboral',
        ];

        foreach ($headers as $coordinate => $label) {
            $sheet->setCellValue($coordinate, $label);
        }
    }

    /** @param iterable<PayrollResult> $results */
    private function writeDataRows(Worksheet $sheet, iterable $results): void
    {
        $row = 6;
        $employeeId = null;
        $employeeTotals = $this->emptyTotals();
        $grandTotals = $this->emptyTotals();

        foreach ($results as $result) {
            $reportingRow = $this->rowAdapter->adapt($result);

            if ($employeeId !== null && $employeeId !== $result->employee_id) {
                $this->writeTotalsRow($sheet, $row++, 'EMPLOYEE SUBTOTAL', $employeeTotals);
                $employeeTotals = $this->emptyTotals();
            }

            $employeeId = $result->employee_id;

            $sheet->setCellValue("A{$row}", $reportingRow['employee_external_id']);
            $this->assertPaymentIdentity($reportingRow);
            $sheet->setCellValueExplicit("B{$row}", $reportingRow['employee_payment_code'], DataType::TYPE_STRING);
            $sheet->setCellValue("C{$row}", $reportingRow['employee_name']);
            $sheet->setCellValue("D{$row}", $reportingRow['employee_job_title']);

            if ($reportingRow['entry_at'] !== null) {
                $sheet->setCellValue("E{$row}", $reportingRow['entry_at'] instanceof \DateTimeInterface
                    ? $reportingRow['entry_at']->format('Y-m-d H:i:s')
                    : $reportingRow['entry_at']);
                $sheet->getStyle("E{$row}")
                    ->getNumberFormat()
                    ->setFormatCode(self::DATE_FORMAT);
            }

            if ($reportingRow['exit_at'] !== null) {
                $sheet->setCellValue("F{$row}", $reportingRow['exit_at'] instanceof \DateTimeInterface
                    ? $reportingRow['exit_at']->format('Y-m-d H:i:s')
                    : $reportingRow['exit_at']);
                $sheet->getStyle("F{$row}")
                    ->getNumberFormat()
                    ->setFormatCode(self::DATE_FORMAT);
            }

            $sheet->setCellValue("G{$row}", $this->hoursFromMinutes($reportingRow['worked_minutes']));
            $sheet->getStyle("G{$row}")
                ->getNumberFormat()
                ->setFormatCode(self::DECIMAL_HOURS_FORMAT);

            $sheet->setCellValue("H{$row}", $this->hoursFromMinutes($reportingRow['ordinary_minutes']));
            $sheet->getStyle("H{$row}")
                ->getNumberFormat()
                ->setFormatCode(self::DECIMAL_HOURS_FORMAT);

            $sheet->setCellValue("I{$row}", $this->hoursFromMinutes($reportingRow['extra_25_minutes']));
            $sheet->setCellValue("J{$row}", $this->hoursFromMinutes($reportingRow['extra_50_minutes']));
            $sheet->setCellValue("K{$row}", $this->hoursFromMinutes($reportingRow['extra_75_minutes']));
            $sheet->setCellValue("L{$row}", $this->hoursFromMinutes($reportingRow['extra_100_minutes']));

            foreach (['I', 'J', 'K', 'L'] as $column) {
                $sheet->getStyle("{$column}{$row}")
                    ->getNumberFormat()
                    ->setFormatCode(self::DECIMAL_HOURS_FORMAT);
            }

            $sheet->setCellValue("M{$row}", $reportingRow['work_date']);

            $this->accumulate($employeeTotals, $reportingRow);
            $this->accumulate($grandTotals, $reportingRow);

            $row++;
        }

        if ($employeeId !== null) {
            $this->writeTotalsRow($sheet, $row++, 'EMPLOYEE SUBTOTAL', $employeeTotals);
        }

        $this->writeTotalsRow($sheet, $row, 'GRAND TOTAL', $grandTotals);
    }

    /** @param iterable<PayrollResult> $results */
    private function writeAuditRows(Worksheet $sheet, PayPeriod $payPeriod, iterable $results): void
    {
        $this->writeAuditTitleRows($sheet, $payPeriod);

        $headers = [
            'Código de empleado', 'Código de pago', 'NOMBRE', 'Cargo', 'Fecha laboral', 'Estado de fila',
            'Entrada', 'Salida', 'Minutos observados', 'Marcas observadas', 'Revisiones de marcas',
            'Minutos ordinarios', 'Minutos Ext 25%', 'Minutos Ext 50%', 'Minutos Ext 75%', 'Minutos Ext 100%',
            'Déficit minutos', 'Déficit estado', 'Déficit motivo', 'Hora extra detectada',
            'Hora extra aprobada', 'Hora extra rechazada', 'Variación', 'Reconocimiento de variación',
            'Transferencia excluida', 'Versión de reglas', 'Tipo de día', 'Vacación', 'Detalle de vacación',
        ];
        $sheet->fromArray($headers, null, 'A5');

        $row = 6;
        foreach ($results as $result) {
            $reportingRow = $this->rowAdapter->adapt($result);
            $this->assertPaymentIdentity($reportingRow);

            $sheet->setCellValue("A{$row}", $reportingRow['employee_external_id']);
            $sheet->setCellValueExplicit("B{$row}", $reportingRow['employee_payment_code'], DataType::TYPE_STRING);
            foreach ([
                'C' => 'employee_name', 'D' => 'employee_job_title', 'E' => 'work_date', 'F' => 'status',
                'G' => 'entry_at', 'H' => 'exit_at', 'I' => 'worked_minutes', 'L' => 'ordinary_minutes',
                'M' => 'extra_25_minutes', 'N' => 'extra_50_minutes', 'O' => 'extra_75_minutes',
                'P' => 'extra_100_minutes', 'Q' => 'shortfall_minutes', 'R' => 'shortfall_state',
                'S' => 'shortfall_reason', 'Y' => 'excluded_transfer_minutes', 'Z' => 'rules_version',
                'AA' => 'day_type', 'AB' => 'vacation_id',
            ] as $column => $key) {
                $sheet->setCellValue("{$column}{$row}", $this->displayValue($reportingRow[$key]));
            }

            $sheet->setCellValue("J{$row}", $this->describeObservedMarks($reportingRow['observed_marks']));
            $sheet->setCellValue("K{$row}", $this->describeMarkRevisions($reportingRow['observed_marks']));
            $sheet->setCellValue("T{$row}", $this->describeOvertime($reportingRow['detected_overtime'], 'detected'));
            $sheet->setCellValue("U{$row}", $this->describeOvertime($reportingRow['approved_overtime'], 'approved'));
            $sheet->setCellValue("V{$row}", $this->describeOvertime($reportingRow['rejected_overtime'], 'rejected'));
            $sheet->setCellValue("W{$row}", $this->describeVariations($reportingRow['variation']));
            $sheet->setCellValue("X{$row}", $this->describeAcknowledgements($reportingRow['acknowledgement']));
            $sheet->setCellValue("AC{$row}", $this->describeVacation($reportingRow['vacation']));
            $row++;
        }

        $sheet->freezePane('A6');
        $sheet->getStyle('A5:AC5')->getFont()->setBold(true);
        $sheet->getStyle('A5:AC5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A5:AC5')->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFE0E0E0'));
        $sheet->getStyle('A5:AC5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A6:AC{$row}")->getAlignment()->setWrapText(true);
    }

    private function writeAuditTitleRows(Worksheet $sheet, PayPeriod $payPeriod): void
    {
        $sheet->setCellValue('A2', sprintf(
            'AUDITORÍA DE ASISTENCIA DEL %s AL %s',
            $payPeriod->start_date->format('d/m/Y'),
            $payPeriod->end_date->format('d/m/Y'),
        ));
        $sheet->mergeCells('A2:AC2');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function displayValue(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
    }

    private function describeObservedMarks(?string $json): ?string
    {
        return $this->describeJson($json, function (array $marks): array {
            return array_map(function (array $mark): string {
                return $this->joinDescriptionParts([
                    $mark['event_at'] ?? null,
                    $mark['status'] ?? null,
                    $mark['source'] ?? null,
                ]);
            }, $marks);
        });
    }

    private function describeMarkRevisions(?string $json): ?string
    {
        return $this->describeJson($json, function (array $marks): array {
            $descriptions = [];
            foreach ($marks as $mark) {
                foreach ($mark['revisions'] ?? [] as $revision) {
                    $descriptions[] = $this->joinDescriptionParts([
                        'Revisión', $revision['changed_at'] ?? null,
                    ]);
                }
            }

            return $descriptions;
        });
    }

    private function describeOvertime(?string $json, string $type): ?string
    {
        return $this->describeJson($json, function (array $items) use ($type): array {
            $descriptions = [];
            foreach ($items as $item) {
                $ranges = match ($type) {
                    'detected' => [['starts_at', 'ends_at', 'minutes']],
                    'approved' => [['approved_starts_at', 'approved_ends_at', 'approved_minutes']],
                    'rejected' => [
                        ['rejected_before_starts_at', 'rejected_before_ends_at', 'rejected_before_minutes'],
                        ['rejected_after_starts_at', 'rejected_after_ends_at', 'rejected_after_minutes'],
                    ],
                };
                $presentRanges = array_filter($ranges, fn (array $range): bool => ($item[$range[0]] ?? null) !== null || ($item[$range[1]] ?? null) !== null);

                foreach ($ranges as [$start, $end, $minutes]) {
                    if (($item[$start] ?? null) !== null || ($item[$end] ?? null) !== null || ($item[$minutes] ?? null) !== null) {
                        $minuteValue = $item[$minutes] ?? ($type === 'rejected' && count($presentRanges) === 1
                            ? $item['rejected_minutes'] ?? null
                            : null);
                        $descriptions[] = $this->joinDescriptionParts([
                            $item[$start] ?? null,
                            $item[$end] ?? null,
                            $minuteValue !== null ? "{$minuteValue} min" : null,
                        ]);
                    }
                }
            }

            return $descriptions;
        });
    }

    private function describeVariations(?string $json): ?string
    {
        return $this->describeJson($json, fn (array $variations): array => array_map(
            fn (array $variation): string => $this->joinDescriptionParts([
                $variation['kind'] ?? null,
                $variation['entry_at'] ?? null,
            ]),
            $variations,
        ));
    }

    private function describeAcknowledgements(?string $json): ?string
    {
        return $this->describeJson($json, fn (array $acknowledgements): array => array_map(
            fn (array $acknowledgement): string => $this->joinDescriptionParts([
                $acknowledgement['reason'] ?? null,
                $acknowledgement['acknowledged_at'] ?? null,
            ]),
            $acknowledgements,
        ));
    }

    private function describeVacation(?string $json): ?string
    {
        if ($json === null || $json === '[]') {
            return null;
        }

        $vacation = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $this->joinDescriptionParts([
            isset($vacation['planned_minutes']) ? $vacation['planned_minutes'].' min pagados' : null,
            $vacation['scheduled_start'] ?? null,
            $vacation['scheduled_end'] ?? null,
        ]);
    }

    /** @param callable(list<array<string, mixed>>): list<string> $describe */
    private function describeJson(?string $json, callable $describe): ?string
    {
        if ($json === null || $json === '[]') {
            return null;
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $descriptions = array_filter($describe($decoded));

        return $descriptions === [] ? null : implode('; ', $descriptions);
    }

    /** @param list<mixed> $parts */
    private function joinDescriptionParts(array $parts): string
    {
        return implode(' — ', array_filter($parts, fn (mixed $part): bool => $part !== null && $part !== ''));
    }

    /** @return array<string, int> */
    private function emptyTotals(): array
    {
        return array_fill_keys([
            'worked_minutes', 'ordinary_minutes', 'extra_25_minutes', 'extra_50_minutes',
            'extra_75_minutes', 'extra_100_minutes',
        ], 0);
    }

    /**
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $reportingRow
     */
    private function accumulate(array &$totals, array $reportingRow): void
    {
        foreach (array_keys($totals) as $key) {
            $totals[$key] += $reportingRow[$key] ?? 0;
        }
    }

    /** @param array<string, int> $totals */
    private function writeTotalsRow(Worksheet $sheet, int $row, string $label, array $totals): void
    {
        $sheet->setCellValue("C{$row}", $label);

        foreach (array_combine(
            ['G', 'H', 'I', 'J', 'K', 'L'],
            ['worked_minutes', 'ordinary_minutes', 'extra_25_minutes', 'extra_50_minutes', 'extra_75_minutes', 'extra_100_minutes'],
        ) as $column => $key) {
            $sheet->setCellValue("{$column}{$row}", "={$totals[$key]}/60");
        }

        $sheet->getStyle("A{$row}:M{$row}")->getFont()->setBold(true);
        $sheet->getStyle("G{$row}:L{$row}")->getNumberFormat()->setFormatCode(self::DECIMAL_HOURS_FORMAT);
    }

    private function hoursFromMinutes(?int $minutes): ?float
    {
        return $minutes === null ? null : $minutes / 60;
    }

    private function applyHeaderStyle(Worksheet $sheet): void
    {
        $range = 'A5:M5';
        $style = $sheet->getStyle($range);

        $style->getFont()->setBold(true);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFE0E0E0'));
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    /** @param array<string, mixed> $row */
    private function assertPaymentIdentity(array $row): void
    {
        if ($row['status'] === 'LEGACY') {
            return;
        }

        if (($row['employee_payment_code'] ?? '') === '' || ($row['employee_job_title'] ?? '') === '') {
            throw new \LogicException('Payroll result is missing payment code or job title.');
        }
    }

    private function spanishMonthName(int $month): string
    {
        $names = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];

        return $names[$month] ?? '';
    }
}
