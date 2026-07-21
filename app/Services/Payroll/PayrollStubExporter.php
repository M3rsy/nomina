<?php

namespace App\Services\Payroll;

use App\Models\Employee;
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
 * Generates a per-employee attendance/payroll stub from stored PayrollResult
 * rows. Includes a totals row for the hours columns.
 */
class PayrollStubExporter
{
    private const DATE_FORMAT = 'yyyy-mm-dd h:mm AM/PM';

    private const DECIMAL_HOURS_FORMAT = '#,##0.00';

    public function export(PayPeriod $payPeriod, Employee $employee): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Comprobante');

        $this->applyColumnWidths($sheet);
        $this->writeHeaderBlock($sheet, $payPeriod, $employee);
        $this->writeTableHeader($sheet);
        $totals = $this->writeDataRows($sheet, $payPeriod, $employee);
        $this->writeTotalsRow($sheet, $totals);
        $this->applyHeaderStyle($sheet);

        $path = tempnam(sys_get_temp_dir(), 'payroll_stub_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    public function filename(PayPeriod $payPeriod, Employee $employee): string
    {
        return "Comprobante {$employee->external_id} {$payPeriod->slug}.xlsx";
    }

    private function applyColumnWidths(Worksheet $sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(22);
        $sheet->getColumnDimension('D')->setWidth(22);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(16);
        $sheet->getColumnDimension('H')->setWidth(16);
        $sheet->getColumnDimension('I')->setWidth(16);
        $sheet->getColumnDimension('J')->setWidth(17);
        $sheet->getColumnDimension('K')->setWidth(14);
        $sheet->getColumnDimension('L')->setWidth(14);
    }

    private function writeHeaderBlock(Worksheet $sheet, PayPeriod $payPeriod, Employee $employee): void
    {
        $sheet->setCellValue('A1', 'Comprobante de nómina');
        $sheet->mergeCells('A1:L1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Empleado:');
        $sheet->setCellValue('B2', $employee->full_name);
        $sheet->setCellValue('A3', 'Código:');
        $sheet->setCellValue('B3', $employee->external_id);
        $sheet->setCellValue('A4', 'Período:');
        $sheet->setCellValue('B4', $payPeriod->name);
        $sheet->setCellValue('A5', 'Del:');
        $sheet->setCellValue('B5', $payPeriod->start_date->format('d/m/Y'));
        $sheet->setCellValue('A6', 'Al:');
        $sheet->setCellValue('B6', $payPeriod->end_date->format('d/m/Y'));

        $sheet->getStyle('A2:A6')->getFont()->setBold(true);
    }

    private function writeTableHeader(Worksheet $sheet): void
    {
        $headers = [
            'A8' => 'Codigo',
            'B8' => 'NOMBRE',
            'C8' => 'Entrada',
            'D8' => 'Salida',
            'E8' => 'Cantidad Horas',
            'F8' => 'Horas Ordinarias',
            'G8' => 'Horas Ext 25%',
            'H8' => 'Horas Ext 50%',
            'I8' => 'Horas Ext 75%',
            'J8' => 'Horas Ext 100%',
            'K8' => 'Ausencia',
            'L8' => 'Justificada',
        ];

        foreach ($headers as $coordinate => $label) {
            $sheet->setCellValue($coordinate, $label);
        }
    }

    /**
     * @return array<string, float|int>
     */
    private function writeDataRows(Worksheet $sheet, PayPeriod $payPeriod, Employee $employee): array
    {
        $results = PayrollResult::withoutCompanyScope()
            ->where('pay_period_id', $payPeriod->id)
            ->where('employee_id', $employee->id)
            ->with('employee')
            ->orderBy('date')
            ->get();

        $totals = [
            'worked_hours' => 0.0,
            'ordinary_hours' => 0.0,
            'extra_25_hours' => 0.0,
            'extra_50_hours' => 0.0,
            'extra_75_hours' => 0.0,
            'extra_100_hours' => 0.0,
        ];

        $row = 9;

        foreach ($results as $result) {
            $sheet->setCellValue("A{$row}", $employee->external_id);
            $sheet->setCellValue("B{$row}", $employee->full_name);

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

            $sheet->setCellValue("E{$row}", $result->worked_hours);
            $sheet->getStyle("E{$row}")
                ->getNumberFormat()
                ->setFormatCode(self::DECIMAL_HOURS_FORMAT);

            $sheet->setCellValue("F{$row}", $result->ordinary_hours);
            $sheet->getStyle("F{$row}")
                ->getNumberFormat()
                ->setFormatCode(self::DECIMAL_HOURS_FORMAT);

            $sheet->setCellValue("G{$row}", $result->extra_25_hours);
            $sheet->setCellValue("H{$row}", $result->extra_50_hours);
            $sheet->setCellValue("I{$row}", $result->extra_75_hours);
            $sheet->setCellValue("J{$row}", $result->extra_100_hours);

            foreach (['G', 'H', 'I', 'J'] as $column) {
                $sheet->getStyle("{$column}{$row}")
                    ->getNumberFormat()
                    ->setFormatCode(self::DECIMAL_HOURS_FORMAT);
            }

            $sheet->setCellValue("K{$row}", $result->is_absence ? 'Sí' : 'No');
            $sheet->setCellValue("L{$row}", $result->is_justified ? 'Sí' : 'No');

            $totals['worked_hours'] += (float) $result->worked_hours;
            $totals['ordinary_hours'] += (float) $result->ordinary_hours;
            $totals['extra_25_hours'] += (float) $result->extra_25_hours;
            $totals['extra_50_hours'] += (float) $result->extra_50_hours;
            $totals['extra_75_hours'] += (float) $result->extra_75_hours;
            $totals['extra_100_hours'] += (float) $result->extra_100_hours;

            $row++;
        }

        return $totals;
    }

    /**
     * @param  array<string, float|int>  $totals
     */
    private function writeTotalsRow(Worksheet $sheet, array $totals): void
    {
        $lastRow = $sheet->getHighestDataRow();
        $totalsRow = $lastRow + 1;

        $sheet->setCellValue("A{$totalsRow}", 'TOTAL');
        $sheet->setCellValue("E{$totalsRow}", $totals['worked_hours']);
        $sheet->setCellValue("F{$totalsRow}", $totals['ordinary_hours']);
        $sheet->setCellValue("G{$totalsRow}", $totals['extra_25_hours']);
        $sheet->setCellValue("H{$totalsRow}", $totals['extra_50_hours']);
        $sheet->setCellValue("I{$totalsRow}", $totals['extra_75_hours']);
        $sheet->setCellValue("J{$totalsRow}", $totals['extra_100_hours']);

        $sheet->getStyle("A{$totalsRow}")->getFont()->setBold(true);
        $sheet->getStyle("E{$totalsRow}:J{$totalsRow}")
            ->getFont()
            ->setBold(true);
        $sheet->getStyle("E{$totalsRow}:F{$totalsRow}")
            ->getNumberFormat()
            ->setFormatCode(self::DECIMAL_HOURS_FORMAT);
        $sheet->getStyle("G{$totalsRow}:J{$totalsRow}")
            ->getNumberFormat()
            ->setFormatCode(self::DECIMAL_HOURS_FORMAT);
    }

    private function applyHeaderStyle(Worksheet $sheet): void
    {
        $range = 'A8:L8';
        $style = $sheet->getStyle($range);

        $style->getFont()->setBold(true);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFE0E0E0'));
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
