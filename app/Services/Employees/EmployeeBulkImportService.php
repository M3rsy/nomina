<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\User;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\GeneralWorkScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EmployeeBulkImportService
{
    public const HEADERS = [
        'Código empleado *',
        'Clave *',
        'Fecha contratación *',
        'Nombre *',
        'Apellido *',
        'Identidad',
        'Sexo',
        'Fecha nacimiento',
        'Dirección',
        'Teléfono',
        'Cargo',
        'Salario esperado',
        'Notas',
    ];

    private const MAX_IMPORT_DATA_ROWS = 1000;

    private const MAX_IMPORT_HEADER_COLUMNS = 13;

    private const REQUIRED_HEADERS = [
        'Código empleado',
        'Clave',
        'Fecha contratación',
        'Nombre',
        'Apellido',
    ];

    /** @return array{count: int, batch_id: int} */
    public function import(UploadedFile $file, int $companyId, User $actor): array
    {
        $batch = EmployeeImportBatch::create([
            'company_id' => $companyId,
            'actor_id' => $actor->id,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => EmployeeImportBatch::FAILED,
        ]);

        try {
            [$rows, $errors, $rowStats] = $this->readRows($file);
            $batch->update($rowStats);

            if ($rows === [] && $errors === []) {
                $errors['import_file'][] = 'El archivo no contiene filas para importar.';
            }

            foreach ($rows as &$row) {
                $row['attributes']['company_id'] = $companyId;
            }
            unset($row);

            $codes = [];
            foreach ($rows as $row) {
                $code = $row['attributes']['external_id'];
                $codes[$code][] = $row['row'];
            }

            foreach ($codes as $code => $rowNumbers) {
                if (count($rowNumbers) > 1) {
                    foreach ($rowNumbers as $rowNumber) {
                        $errors["rows.{$rowNumber}.external_id"][] = 'El código de empleado está repetido dentro del archivo.';
                    }
                }
            }

            if ($codes !== []) {
                $existingCodes = Employee::withoutCompanyScope()
                    ->where('company_id', $companyId)
                    ->whereIn('external_id', array_keys($codes))
                    ->pluck('external_id')
                    ->all();

                foreach ($existingCodes as $code) {
                    foreach ($codes[$code] as $rowNumber) {
                        $errors["rows.{$rowNumber}.external_id"][] = 'El código de empleado ya existe en esta empresa.';
                    }
                }
            }

            foreach ($rows as $row) {
                try {
                    app(GeneralWorkScheduleResolver::class)->resolve($companyId, $row['attributes']['hired_at']);
                } catch (ValidationException) {
                    $errors["rows.{$row['row']}.hired_at"][] = 'No existe una jornada general vigente para la fecha de contratación.';
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            DB::transaction(function () use ($rows, $actor): void {
                foreach ($rows as $row) {
                    $attributes = $row['attributes'];
                    $positionTitle = $row['position_title'];
                    $hiredAt = $attributes['hired_at'];
                    $reason = 'Importación masiva de empleados';

                    $assignment = app(EmployeeScheduleAssigner::class)->createAndAssignGeneral(
                        $attributes,
                        $hiredAt,
                        $reason,
                        $actor,
                    );

                    if ($positionTitle !== null) {
                        app(EmployeePositionAssigner::class)->assign(
                            $assignment->employee,
                            $positionTitle,
                            $hiredAt,
                            $reason,
                            $actor,
                        );
                    }
                }
            });

            $batch->update([
                'status' => EmployeeImportBatch::COMPLETED,
                'imported_rows' => count($rows),
                'error_summary' => null,
                'error_details' => null,
            ]);

            return ['count' => count($rows), 'batch_id' => $batch->id];
        } catch (ValidationException $exception) {
            $this->failBatch($batch, $exception->errors());

            throw $exception;
        } catch (QueryException $exception) {
            if (! $this->isEmployeeExternalIdUniqueViolation($exception)) {
                $this->failBatch($batch, ['import_file' => ['No se pudo completar la importación.']]);

                throw $exception;
            }

            $errors = [
                'import_file' => [
                    'Otro proceso creó uno de estos códigos de empleado durante la importación. Volvé a validar el archivo.',
                ],
            ];
            $this->failBatch($batch, $errors);

            throw ValidationException::withMessages($errors);
        } catch (\Throwable $exception) {
            $this->failBatch($batch, ['import_file' => ['No se pudo completar la importación.']]);

            throw $exception;
        }
    }

    public function writeTemplate(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        try {
            $sheet->setTitle('Empleados');
            foreach (self::HEADERS as $index => $header) {
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).'1');
                $cell->setValueExplicit($header, DataType::TYPE_STRING);
                $cell->getStyle()->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $cell->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
                $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getColumnDimensionByColumn($index + 1)->setWidth(max(18, mb_strlen($header) + 4));
            }

            $example = [
                '1001', 'CLAVE-01', '2026-01-15', 'Ana', 'Pérez', '123456789', 'F',
                '1990-05-20', 'Av. Central 123', '8888-8888', 'Administradora', '25000.00', 'Ejemplo: completar o eliminar esta fila',
            ];
            foreach ($example as $index => $value) {
                $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).'2')->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
            $sheet->getStyle('A1:M2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->freezePane('A2');
            $sheet->getAutoFilter()->setRange('A1:M2');

            $instructions = $spreadsheet->createSheet();
            $instructions->setTitle('Instrucciones');
            $instructions->setCellValue('A1', 'Importación masiva de empleados');
            $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $instructions->setCellValue('A3', 'Complete la hoja Empleados y elimine la fila de ejemplo antes de importar.');
            $instructions->setCellValue('A4', 'Las columnas con * son obligatorias. La fecha debe usar el formato AAAA-MM-DD.');
            $instructions->setCellValue('A5', 'Sexo acepta M, F u O. La importación es todo o nada: si hay un error no se crea ningún empleado.');
            $instructions->setCellValue('A6', 'La jornada general se asigna desde la fecha de contratación.');
            $instructions->getColumnDimension('A')->setWidth(110);
            $instructions->getStyle('A3:A6')->getAlignment()->setWrapText(true);

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @return array{0: list<array{row: int, attributes: array<string, mixed>, position_title: ?string}>, 1: array<string, list<string>>, 2: array{total_rows: int, read_rows: int}} */
    private function readRows(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (\Throwable) {
            return [
                [],
                ['import_file' => ['No se pudo leer el archivo Excel. Descargue la plantilla oficial y vuelva a intentarlo.']],
                ['total_rows' => 0, 'read_rows' => 0],
            ];
        }

        $sheet = $spreadsheet->getSheet(0);
        $errors = [];
        $highestRow = $sheet->getHighestRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $rowStats = ['total_rows' => max(0, $highestRow - 1), 'read_rows' => 0];

        if ($highestRow - 1 > self::MAX_IMPORT_DATA_ROWS) {
            $errors['import_file'][] = sprintf(
                'El archivo no puede contener más de %d filas de datos.',
                self::MAX_IMPORT_DATA_ROWS,
            );
        }

        if ($highestColumnIndex > self::MAX_IMPORT_HEADER_COLUMNS) {
            $errors['import_file'][] = sprintf(
                'El archivo no puede contener más de %d columnas.',
                self::MAX_IMPORT_HEADER_COLUMNS,
            );
        }

        if ($highestRow - 1 > self::MAX_IMPORT_DATA_ROWS) {
            return [[], $errors, $rowStats];
        }

        $headerEndColumn = Coordinate::stringFromColumnIndex(min($highestColumnIndex, self::MAX_IMPORT_HEADER_COLUMNS));
        $headerValues = $sheet->rangeToArray('A1:'.$headerEndColumn.'1', null, false, false)[0] ?? [];
        $columns = [];
        foreach ($headerValues as $index => $header) {
            $normalized = $this->normalizeHeader($header);
            if ($normalized === '') {
                continue;
            }

            if (isset($columns[$normalized])) {
                $errors['import_file'][] = "La columna «{$normalized}» está repetida.";

                continue;
            }

            $columns[$normalized] = $index + 1;
        }

        foreach (self::REQUIRED_HEADERS as $required) {
            if (! isset($columns[$required])) {
                $errors['import_file'][] = "Falta la columna obligatoria «{$required}».";
            }
        }

        if ($errors !== []) {
            return [[], $errors, $rowStats];
        }

        $rows = [];
        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $values = [];
            $hasValue = false;
            foreach ($columns as $header => $column) {
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$rowNumber);
                $value = $cell->getValue();
                $values[$header] = $value;
                $hasValue = $hasValue || trim((string) $value) !== '';
            }

            if (! $hasValue) {
                continue;
            }

            $rowStats['read_rows']++;
            $rowErrors = [];
            $attributes = [
                'external_id' => $this->text($values['Código empleado'] ?? null),
                'payment_code' => $this->nullableText($values['Clave'] ?? null),
                'first_name' => $this->text($values['Nombre'] ?? null),
                'last_name' => $this->text($values['Apellido'] ?? null),
                'dni' => $this->nullableText($values['Identidad'] ?? null),
                'sex' => $this->nullableText($values['Sexo'] ?? null),
                'birth_date' => $this->date($values['Fecha nacimiento'] ?? null, $this->cell($sheet, $columns['Fecha nacimiento'] ?? null, $rowNumber), $rowErrors, 'birth_date'),
                'address' => $this->nullableText($values['Dirección'] ?? null),
                'phone' => $this->nullableText($values['Teléfono'] ?? null),
                'job_title' => $this->nullableText($values['Cargo'] ?? null),
                'expected_salary' => $this->nullableText($values['Salario esperado'] ?? null),
                'hired_at' => $this->date($values['Fecha contratación'] ?? null, $this->cell($sheet, $columns['Fecha contratación'], $rowNumber), $rowErrors, 'hired_at'),
                'notes' => $this->nullableText($values['Notas'] ?? null),
                'company_id' => null,
                'is_active' => true,
                'metadata' => null,
            ];

            $validator = validator($attributes, [
                'external_id' => ['required', 'string', 'max:50'],
                'payment_code' => ['required', 'string', 'max:50'],
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'dni' => ['nullable', 'string', 'max:32', 'regex:/^\d*$/'],
                'sex' => ['nullable', 'in:M,F,O'],
                'birth_date' => ['nullable', 'date'],
                'address' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:32'],
                'job_title' => ['nullable', 'string', 'max:100'],
                'expected_salary' => ['nullable', 'numeric', 'decimal:0,2'],
                'hired_at' => ['required', 'date'],
                'notes' => ['nullable', 'string'],
            ], [
                'external_id.required' => 'El código de empleado es obligatorio.',
                'payment_code.required' => 'La clave es obligatoria.',
                'first_name.required' => 'El nombre es obligatorio.',
                'last_name.required' => 'El apellido es obligatorio.',
                'hired_at.required' => 'La fecha de contratación es obligatoria.',
                'hired_at.date' => 'La fecha de contratación no es válida.',
                'birth_date.date' => 'La fecha de nacimiento no es válida.',
                'sex.in' => 'El sexo debe ser M, F u O.',
                'dni.regex' => 'La identidad debe contener solo números.',
            ]);
            foreach ($validator->errors()->all() as $message) {
                $rowErrors[] = $message;
            }

            if ($rowErrors !== []) {
                foreach ($rowErrors as $message) {
                    $errors["rows.{$rowNumber}.data"][] = $message;
                }

                continue;
            }

            $attributes['company_id'] = 0;
            $positionTitle = $attributes['job_title'] !== null ? trim((string) $attributes['job_title']) : null;
            $attributes['job_title'] = $positionTitle !== null && CarbonImmutable::parse($attributes['hired_at'])->lte(CarbonImmutable::today())
                ? $positionTitle
                : null;
            $attributes['dni'] ??= '';
            $rows[] = [
                'row' => $rowNumber,
                'attributes' => $attributes,
                'position_title' => $positionTitle,
            ];
        }

        return [$rows, $errors, $rowStats];
    }

    /** @param array<string, list<string>> $errors */
    private function failBatch(EmployeeImportBatch $batch, array $errors): void
    {
        $batch->update([
            'status' => EmployeeImportBatch::FAILED,
            'imported_rows' => 0,
            'error_summary' => $this->summarizeErrors($errors),
            'error_details' => $errors,
        ]);
    }

    /** @param array<string, list<string>> $errors */
    private function summarizeErrors(array $errors): array
    {
        $summary = [];

        foreach ($errors as $key => $messages) {
            preg_match('/^rows\\.(\\d+)/', $key, $match);
            $row = isset($match[1]) ? (int) $match[1] : null;

            foreach ($messages as $message) {
                $summaryKey = $message;
                $summary[$summaryKey] ??= ['message' => $message, 'count' => 0, 'rows' => []];
                $summary[$summaryKey]['count']++;
                if ($row !== null && ! in_array($row, $summary[$summaryKey]['rows'], true)) {
                    $summary[$summaryKey]['rows'][] = $row;
                }
            }
        }

        return array_values($summary);
    }

    private function isEmployeeExternalIdUniqueViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $haystack = strtolower(implode(' ', array_map(
            static fn (mixed $value): string => (string) $value,
            [$exception->getMessage(), ...$errorInfo],
        )));
        $isUnique = in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || in_array((string) ($errorInfo[1] ?? ''), ['19', '1062'], true)
            || str_contains($haystack, 'unique constraint failed')
            || str_contains($haystack, 'duplicate key value violates unique');

        return $isUnique
            && str_contains($haystack, 'employee')
            && (str_contains($haystack, 'external_id') || str_contains($haystack, 'company_id_external_id'));
    }

    private function normalizeHeader(mixed $header): string
    {
        return trim((string) preg_replace('/\s*\*\s*$/u', '', (string) $header));
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function nullableText(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value === '' ? null : $value;
    }

    /** @param list<string> $errors */
    private function date(mixed $value, ?Cell $cell, array &$errors, string $field): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            if (is_numeric($value) && $cell !== null && Date::isDateTime($cell)) {
                return CarbonImmutable::instance(Date::excelToDateTimeObject((float) $value))->toDateString();
            }

            $text = trim((string) $value);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $text)) {
                throw new \InvalidArgumentException('La fecha debe usar el formato YYYY-MM-DD.');
            }

            $date = CarbonImmutable::createFromFormat('!Y-m-d', $text);
            if ($date === false || $date->format('Y-m-d') !== $text) {
                throw new \InvalidArgumentException('La fecha no es válida.');
            }

            return $date->toDateString();
        } catch (\Throwable) {
            $errors[] = $field === 'hired_at'
                ? 'La fecha de contratación no es válida.'
                : 'La fecha de nacimiento no es válida.';

            return null;
        }
    }

    private function cell($sheet, ?int $column, int $row): ?Cell
    {
        return $column === null ? null : $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row);
    }
}
