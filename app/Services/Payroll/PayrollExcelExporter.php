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
    ];

    private const DATE_FORMAT = 'yyyy-mm-dd h:mm AM/PM';

    private const DECIMAL_HOURS_FORMAT = '#,##0.00';

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
        $lastColumn = 'J';

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
        ];

        foreach ($headers as $coordinate => $label) {
            $sheet->setCellValue($coordinate, $label);
        }
    }

    private function writeDataRows(Worksheet $sheet, PayPeriod $payPeriod): void
    {
        $results = PayrollResult::withoutCompanyScope()
            ->where('pay_period_id', $payPeriod->id)
            ->with('employee')
            ->orderBy('employee_id')
            ->orderBy('date')
            ->get();

        $row = 6;

        foreach ($results as $result) {
            $employee = $result->employee;

            $sheet->setCellValue("A{$row}", $employee?->external_id ?? '');
            $sheet->setCellValue("B{$row}", $employee?->full_name ?? '');

            if ($result->entry_at !== null) {
                $sheet->setCellValue("C{$row}", $result->entry_at->toDateTimeString());
                $sheet->getStyle("C{$row}")
                    ->getNumberFormat()
                    ->setFormatCode(self::DATE_FORMAT);
            }

            if ($result->exit_at !== null) {
                $sheet->setCellValue("D{$row}", $result->exit_at->toDateTimeString());
                $sheet->getStyle("D{$row}")
                    ->getNumberFormat()
                    ->setFormatCode(self::DATE_FORMAT);
            }

            $sheet->setCellValue("E{$row}", $this->hoursFromMinutes($result->worked_minutes));
            $sheet->getStyle("E{$row}")
                ->getNumberFormat()
                ->setFormatCode(self::DECIMAL_HOURS_FORMAT);

            $sheet->setCellValue("F{$row}", $this->hoursFromMinutes($result->ordinary_minutes));
            $sheet->getStyle("F{$row}")
                ->getNumberFormat()
                ->setFormatCode(self::DECIMAL_HOURS_FORMAT);

            $sheet->setCellValue("G{$row}", $this->hoursFromMinutes($result->extra_25_minutes));
            $sheet->setCellValue("H{$row}", $this->hoursFromMinutes($result->extra_50_minutes));
            $sheet->setCellValue("I{$row}", $this->hoursFromMinutes($result->extra_75_minutes));
            $sheet->setCellValue("J{$row}", $this->hoursFromMinutes($result->extra_100_minutes));

            foreach (['G', 'H', 'I', 'J'] as $column) {
                $sheet->getStyle("{$column}{$row}")
                    ->getNumberFormat()
                    ->setFormatCode(self::DECIMAL_HOURS_FORMAT);
            }

            $row++;
        }
    }

    private function hoursFromMinutes(int $minutes): float
    {
        return $minutes / 60;
    }

    private function applyHeaderStyle(Worksheet $sheet): void
    {
        $range = 'A5:J5';
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
