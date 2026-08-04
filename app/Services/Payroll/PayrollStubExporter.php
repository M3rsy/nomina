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

    public function __construct(private ?PayrollReportingRowAdapter $rowAdapter = null)
    {
        $this->rowAdapter ??= new PayrollReportingRowAdapter;
    }

    public function export(PayPeriod $payPeriod, Employee $employee): string
    {
        $identity = $this->resolveEmployeeIdentity($payPeriod, $employee);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Comprobante');

        $this->applyColumnWidths($sheet);
        $this->writeHeaderBlock($sheet, $payPeriod, $identity);
        $this->writeTableHeader($sheet);
        $totals = $this->writeDataRows($sheet, $payPeriod, $employee);
        $this->writeTotalsRows($sheet, $totals);
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
        $sheet->mergeCells('A1:AG1');
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
            'M8' => 'Fecha laboral',
            'N8' => 'Estado de fila',
            'O8' => 'Minutos observados',
            'P8' => 'Marcas observadas',
            'Q8' => 'Revisiones de marcas',
            'R8' => 'Minutos ordinarios',
            'S8' => 'Minutos Ext 25%',
            'T8' => 'Minutos Ext 50%',
            'U8' => 'Minutos Ext 75%',
            'V8' => 'Minutos Ext 100%',
            'W8' => 'Déficit minutos',
            'X8' => 'Déficit estado',
            'Y8' => 'Déficit motivo',
            'Z8' => 'Hora extra detectada',
            'AA8' => 'Hora extra aprobada',
            'AB8' => 'Hora extra rechazada',
            'AC8' => 'Variación',
            'AD8' => 'Reconocimiento de variación',
            'AE8' => 'Minutos extra aprobados',
            'AF8' => 'Transferencia excluida',
            'AG8' => 'Versión de reglas',
        ];

        foreach ($headers as $coordinate => $label) {
            $sheet->setCellValue($coordinate, $label);
        }
    }

    /**
     * @return array<string, int>
     */
    private function writeDataRows(Worksheet $sheet, PayPeriod $payPeriod, Employee $employee): array
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
            $reportingRow = $this->rowAdapter->adapt($result);
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

            $sheet->setCellValue("K{$row}", $result->is_absence ? 'Sí' : 'No');
            $sheet->setCellValue("L{$row}", $result->is_justified ? 'Sí' : 'No');

            foreach (array_combine(
                ['M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG'],
                ['work_date', 'status', 'worked_minutes', 'observed_marks', 'mark_revisions', 'ordinary_minutes', 'extra_25_minutes', 'extra_50_minutes', 'extra_75_minutes', 'extra_100_minutes', 'shortfall_minutes', 'shortfall_state', 'shortfall_reason', 'detected_overtime', 'approved_overtime', 'rejected_overtime', 'variation', 'acknowledgement', 'approved_overtime_minutes', 'excluded_transfer_minutes', 'rules_version'],
            ) as $column => $key) {
                $sheet->setCellValue("{$column}{$row}", $reportingRow[$key]);
            }

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $reportingRow[$key] ?? 0;
            }

            $row++;
        }

        return $totals;
    }

    /** @return array<string, string> */
    private function resolveEmployeeIdentity(PayPeriod $payPeriod, Employee $employee): array
    {
        $result = PayrollResult::withoutCompanyScope()
            ->where('pay_period_id', $payPeriod->id)
            ->where('employee_id', $employee->id)
            ->orderBy('date')
            ->first();

        if ($result === null) {
            return [
                'employee_external_id' => $employee->external_id,
                'employee_name' => $employee->full_name,
            ];
        }

        $reportingRow = $this->rowAdapter->adapt($result);

        return [
            'employee_external_id' => (string) ($reportingRow['employee_external_id'] ?? ''),
            'employee_name' => (string) ($reportingRow['employee_name'] ?? ''),
        ];
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function writeTotalsRows(Worksheet $sheet, array $totals): void
    {
        $lastRow = $sheet->getHighestDataRow();

        $this->writeTotalsRow($sheet, $lastRow + 1, 'EMPLOYEE SUBTOTAL', $totals);
        $this->writeTotalsRow($sheet, $lastRow + 2, 'GRAND TOTAL', $totals);
    }

    /** @param array<string, int> $totals */
    private function writeTotalsRow(Worksheet $sheet, int $row, string $label, array $totals): void
    {
        $sheet->setCellValue("A{$row}", $label);

        foreach (array_combine(
            ['E', 'F', 'G', 'H', 'I', 'J'],
            ['O', 'R', 'S', 'T', 'U', 'V'],
        ) as $column => $sourceColumn) {
            $sheet->setCellValue("{$column}{$row}", "={$sourceColumn}{$row}/60");
        }

        foreach (array_combine(
            ['O', 'R', 'S', 'T', 'U', 'V'],
            ['worked_minutes', 'ordinary_minutes', 'extra_25_minutes', 'extra_50_minutes', 'extra_75_minutes', 'extra_100_minutes'],
        ) as $column => $key) {
            $sheet->setCellValue("{$column}{$row}", $totals[$key]);
        }

        $sheet->getStyle("A{$row}:V{$row}")->getFont()->setBold(true);
        $sheet->getStyle("E{$row}:J{$row}")->getNumberFormat()->setFormatCode(self::DECIMAL_HOURS_FORMAT);
    }

    private function hoursFromMinutes(int $minutes): float
    {
        return $minutes / 60;
    }

    private function applyHeaderStyle(Worksheet $sheet): void
    {
        $range = 'A8:AG8';
        $style = $sheet->getStyle($range);

        $style->getFont()->setBold(true);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFE0E0E0'));
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
