<?php

namespace App\Services\Payroll;

use App\Models\PayPeriod;
use App\Models\PayrollResult;
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
        'B' => 30,
        'C' => 22,
        'D' => 22,
        'E' => 16,
        'F' => 18,
        'G' => 16,
        'H' => 16,
        'I' => 16,
        'J' => 17,
        'K' => 15,
        'L' => 13,
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
        $sheet->setTitle('Hoja1');

        $this->applyColumnWidths($sheet);
        $this->writeTitleRows($sheet, $payPeriod);
        $this->writeHeaderRow($sheet);
        $this->writeDataRows($sheet, $payPeriod);
        $this->applyHeaderStyle($sheet);

        $path = tempnam(sys_get_temp_dir(), 'payroll_export_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    public function filename(PayPeriod $payPeriod): string
    {
        $start = $payPeriod->start_date->format('Ymd');
        $end = $payPeriod->end_date->format('Ymd');

        return "Asistencia {$start} hasta {$end}.xlsx";
    }

    private function applyColumnWidths(Worksheet $sheet): void
    {
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function writeTitleRows(Worksheet $sheet, PayPeriod $payPeriod): void
    {
        $lastColumn = 'AD';

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
            'A5' => 'Codigo',
            'B5' => 'NOMBRE',
            'C5' => 'Entrada',
            'D5' => 'Salida',
            'E5' => 'Cantidad Horas',
            'F5' => 'Horas Ordinarias',
            'G5' => 'Horas Ext 25%',
            'H5' => 'Horas Ext 50%',
            'I5' => 'Horas Ext 75%',
            'J5' => 'Horas Ext 100%',
            'K5' => 'Fecha laboral',
            'L5' => 'Estado de fila',
            'M5' => 'Minutos observados',
            'N5' => 'Marcas observadas',
            'O5' => 'Revisiones de marcas',
            'P5' => 'Minutos ordinarios',
            'Q5' => 'Minutos Ext 25%',
            'R5' => 'Minutos Ext 50%',
            'S5' => 'Minutos Ext 75%',
            'T5' => 'Minutos Ext 100%',
            'U5' => 'Déficit minutos',
            'V5' => 'Déficit estado',
            'W5' => 'Déficit motivo',
            'X5' => 'Hora extra detectada',
            'Y5' => 'Hora extra aprobada',
            'Z5' => 'Hora extra rechazada',
            'AA5' => 'Variación',
            'AB5' => 'Reconocimiento de variación',
            'AC5' => 'Transferencia excluida',
            'AD5' => 'Versión de reglas',
        ];

        foreach ($headers as $coordinate => $label) {
            $sheet->setCellValue($coordinate, $label);
        }
    }

    private function writeDataRows(Worksheet $sheet, PayPeriod $payPeriod): void
    {
        $results = PayrollResult::withoutCompanyScope()
            ->where('pay_period_id', $payPeriod->id)
            ->orderBy('employee_id')
            ->orderBy('date')
            ->get();

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
            $sheet->setCellValue("B{$row}", $reportingRow['employee_name']);

            if ($reportingRow['entry_at'] !== null) {
                $sheet->setCellValue("C{$row}", $reportingRow['entry_at'] instanceof \DateTimeInterface
                    ? $reportingRow['entry_at']->format('Y-m-d H:i:s')
                    : $reportingRow['entry_at']);
                $sheet->getStyle("C{$row}")
                    ->getNumberFormat()
                    ->setFormatCode(self::DATE_FORMAT);
            }

            if ($reportingRow['exit_at'] !== null) {
                $sheet->setCellValue("D{$row}", $reportingRow['exit_at'] instanceof \DateTimeInterface
                    ? $reportingRow['exit_at']->format('Y-m-d H:i:s')
                    : $reportingRow['exit_at']);
                $sheet->getStyle("D{$row}")
                    ->getNumberFormat()
                    ->setFormatCode(self::DATE_FORMAT);
            }

            $sheet->setCellValue("E{$row}", $this->hoursFromMinutes($reportingRow['worked_minutes']));
            $sheet->getStyle("E{$row}")
                ->getNumberFormat()
                ->setFormatCode(self::DECIMAL_HOURS_FORMAT);

            $sheet->setCellValue("F{$row}", $this->hoursFromMinutes($reportingRow['ordinary_minutes']));
            $sheet->getStyle("F{$row}")
                ->getNumberFormat()
                ->setFormatCode(self::DECIMAL_HOURS_FORMAT);

            $sheet->setCellValue("G{$row}", $this->hoursFromMinutes($reportingRow['extra_25_minutes']));
            $sheet->setCellValue("H{$row}", $this->hoursFromMinutes($reportingRow['extra_50_minutes']));
            $sheet->setCellValue("I{$row}", $this->hoursFromMinutes($reportingRow['extra_75_minutes']));
            $sheet->setCellValue("J{$row}", $this->hoursFromMinutes($reportingRow['extra_100_minutes']));

            foreach (['G', 'H', 'I', 'J'] as $column) {
                $sheet->getStyle("{$column}{$row}")
                    ->getNumberFormat()
                    ->setFormatCode(self::DECIMAL_HOURS_FORMAT);
            }

            foreach (array_combine(
                ['K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD'],
                ['work_date', 'status', 'worked_minutes', 'observed_marks', 'mark_revisions', 'ordinary_minutes', 'extra_25_minutes', 'extra_50_minutes', 'extra_75_minutes', 'extra_100_minutes', 'shortfall_minutes', 'shortfall_state', 'shortfall_reason', 'detected_overtime', 'approved_overtime', 'rejected_overtime', 'variation', 'acknowledgement', 'excluded_transfer_minutes', 'rules_version'],
            ) as $column => $key) {
                $sheet->setCellValue("{$column}{$row}", $reportingRow[$key]);
            }

            $this->accumulate($employeeTotals, $reportingRow);
            $this->accumulate($grandTotals, $reportingRow);

            $row++;
        }

        if ($employeeId !== null) {
            $this->writeTotalsRow($sheet, $row++, 'EMPLOYEE SUBTOTAL', $employeeTotals);
        }

        $this->writeTotalsRow($sheet, $row, 'GRAND TOTAL', $grandTotals);
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
        $sheet->setCellValue("B{$row}", $label);

        foreach (array_combine(
            ['E', 'F', 'G', 'H', 'I', 'J'],
            ['M', 'P', 'Q', 'R', 'S', 'T'],
        ) as $column => $sourceColumn) {
            $sheet->setCellValue("{$column}{$row}", "={$sourceColumn}{$row}/60");
        }

        foreach (array_combine(
            ['M', 'P', 'Q', 'R', 'S', 'T'],
            ['worked_minutes', 'ordinary_minutes', 'extra_25_minutes', 'extra_50_minutes', 'extra_75_minutes', 'extra_100_minutes'],
        ) as $column => $key) {
            $sheet->setCellValue("{$column}{$row}", $totals[$key]);
        }

        $sheet->getStyle("B{$row}:T{$row}")->getFont()->setBold(true);
        $sheet->getStyle("E{$row}:J{$row}")->getNumberFormat()->setFormatCode(self::DECIMAL_HOURS_FORMAT);
    }

    private function hoursFromMinutes(?int $minutes): ?float
    {
        return $minutes === null ? null : $minutes / 60;
    }

    private function applyHeaderStyle(Worksheet $sheet): void
    {
        $range = 'A5:AD5';
        $style = $sheet->getStyle($range);

        $style->getFont()->setBold(true);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFE0E0E0'));
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
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
