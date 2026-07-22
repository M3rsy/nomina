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
        $identity = $this->resolveEmployeeIdentity($payPeriod, $employee);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Comprobante');

        $this->applyColumnWidths($sheet);
        $this->writeHeaderBlock($sheet, $payPeriod, $identity);
        $this->writeTableHeader($sheet);
        $totals = $this->writeDataRows($sheet, $payPeriod, $employee, $identity);
        $this->writeTotalsRow($sheet, $totals);
        $this->applyHeaderStyle($sheet);

        $path = tempnam(sys_get_temp_dir(), 'payroll_stub_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    public function filename(PayPeriod $payPeriod, Employee $employee): string
    {
        $identity = $this->resolveEmployeeIdentity($payPeriod, $employee);

        return "Comprobante {$identity['employee_external_id']} {$payPeriod->slug}.xlsx";
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

    /** @param array<string, string> $identity */
    private function writeHeaderBlock(Worksheet $sheet, PayPeriod $payPeriod, array $identity): void
    {
        $sheet->setCellValue('A1', 'Comprobante de nómina');
        $sheet->mergeCells('A1:L1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Empleado:');
        $sheet->setCellValue('B2', $identity['employee_name']);
        $sheet->setCellValue('A3', 'Código:');
        $sheet->setCellValue('B3', $identity['employee_external_id']);
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
     * @return array<string, int>
     */
    private function writeDataRows(Worksheet $sheet, PayPeriod $payPeriod, Employee $employee, array $identity): array
    {
        $results = PayrollResult::withoutCompanyScope()
            ->where('pay_period_id', $payPeriod->id)
            ->where('employee_id', $employee->id)
            ->orderBy('date')
            ->get();

        $totals = [
            'worked_minutes' => 0,
            'ordinary_minutes' => 0,
            'extra_25_minutes' => 0,
            'extra_50_minutes' => 0,
            'extra_75_minutes' => 0,
            'extra_100_minutes' => 0,
        ];

        $row = 9;

        foreach ($results as $result) {
            $sheet->setCellValue("A{$row}", $result->employee_external_id ?: $identity['employee_external_id']);
            $sheet->setCellValue("B{$row}", $result->employee_name ?: $identity['employee_name']);

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

            $sheet->setCellValue("K{$row}", $result->is_absence ? 'Sí' : 'No');
            $sheet->setCellValue("L{$row}", $result->is_justified ? 'Sí' : 'No');

            $totals['worked_minutes'] += $result->worked_minutes;
            $totals['ordinary_minutes'] += $result->ordinary_minutes;
            $totals['extra_25_minutes'] += $result->extra_25_minutes;
            $totals['extra_50_minutes'] += $result->extra_50_minutes;
            $totals['extra_75_minutes'] += $result->extra_75_minutes;
            $totals['extra_100_minutes'] += $result->extra_100_minutes;

            $row++;
        }

        return $totals;
    }

    /** @return array<string, string> */
    private function resolveEmployeeIdentity(PayPeriod $payPeriod, Employee $employee): array
    {
        $snapshot = PayrollResult::withoutCompanyScope()
            ->where('pay_period_id', $payPeriod->id)
            ->where('employee_id', $employee->id)
            ->orderBy('date')
            ->first([
                'employee_external_id',
                'employee_name',
            ]);

        return [
            'employee_external_id' => $snapshot?->employee_external_id ?: $employee->external_id,
            'employee_name' => $snapshot?->employee_name ?: $employee->full_name,
        ];
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function writeTotalsRow(Worksheet $sheet, array $totals): void
    {
        $lastRow = $sheet->getHighestDataRow();
        $totalsRow = $lastRow + 1;

        $sheet->setCellValue("A{$totalsRow}", 'TOTAL');
        $sheet->setCellValue("E{$totalsRow}", $this->hoursFromMinutes($totals['worked_minutes']));
        $sheet->setCellValue("F{$totalsRow}", $this->hoursFromMinutes($totals['ordinary_minutes']));
        $sheet->setCellValue("G{$totalsRow}", $this->hoursFromMinutes($totals['extra_25_minutes']));
        $sheet->setCellValue("H{$totalsRow}", $this->hoursFromMinutes($totals['extra_50_minutes']));
        $sheet->setCellValue("I{$totalsRow}", $this->hoursFromMinutes($totals['extra_75_minutes']));
        $sheet->setCellValue("J{$totalsRow}", $this->hoursFromMinutes($totals['extra_100_minutes']));

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

    private function hoursFromMinutes(int $minutes): float
    {
        return $minutes / 60;
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
