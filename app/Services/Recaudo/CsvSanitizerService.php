<?php

declare(strict_types=1);

namespace App\Services\Recaudo;

use App\DTOs\Recaudo\SanitizedCsvResultDto;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class CsvSanitizerService
{
    /**
     * Columnas esperadas en la tabla por data source.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMN_MAP = [
        'BASCAR' => [
            'run_id',
            'num_tomador',
            'fecha_inicio_vig',
            'valor_total_fact',
            'periodo',
            'composite_key',
            'data',
            'cantidad_trabajadores',
            'observacion_trabajadores',
            'sheet_name',
        ],
        'BAPRPO' => [
            'run_id',
            'data',
            'sheet_name',
        ],
        'DATPOL' => [
            'run_id',
            'data',
            'sheet_name',
        ],
    ];

    /**
     * @return array<string>
     */
    public function getSupportedDataSources(): array
    {
        return array_keys(self::COLUMN_MAP);
    }

    public function supports(string $dataSourceCode): bool
    {
        return array_key_exists($dataSourceCode, self::COLUMN_MAP);
    }

    public function getColumnMap(string $dataSourceCode): array
    {
        if (!$this->supports($dataSourceCode)) {
            throw new RuntimeException('Data source no soportado para mapeo: ' . $dataSourceCode);
        }

        return self::COLUMN_MAP[$dataSourceCode];
    }

    public function sanitize(
        string $originalCsvPath,
        int $runId,
        string $dataSourceCode
    ): SanitizedCsvResultDto {
        if (!$this->supports($dataSourceCode)) {
            throw new RuntimeException('Data source no soportado para sanitización: ' . $dataSourceCode);
        }

        $transformedCsvPath = $originalCsvPath . '.transformed.csv';

        $input = fopen($originalCsvPath, 'rb');
        if ($input === false) {
            throw new RuntimeException('No se pudo abrir CSV: ' . $originalCsvPath);
        }

        $output = fopen($transformedCsvPath, 'wb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('No se pudo crear CSV transformado: ' . $transformedCsvPath);
        }

        $headerLine = fgets($input);
        if ($headerLine === false) {
            fclose($input);
            fclose($output);
            throw new RuntimeException('CSV sin header: ' . $originalCsvPath);
        }

        $headers = str_getcsv(rtrim($headerLine, "\r\n"), ';', '"', '');

        $hasSpecificColumns = $dataSourceCode === 'BASCAR';
        $columnIndex = array_flip($headers);

        if ($hasSpecificColumns) {
            $this->assertBascarColumns($columnIndex, $originalCsvPath);
            fwrite($output, implode(';', $this->getColumnMap($dataSourceCode)) . "\n");
        } else {
            fwrite($output, 'run_id;data;sheet_name' . "\n");
        }

        $rowsProcessed = 0;
        $rowsWithWarnings = [];
        $currentLine = 1;

        while (($line = fgets($input)) !== false) {
            $currentLine++;
            $row = str_getcsv(rtrim($line, "\r\n"), ';', '"', '');

            if ($this->isRowEmpty($row)) {
                continue;
            }

            $hadWarning = false;

            if ($hasSpecificColumns) {
                [$outputRow, $hadWarning] = $this->processBascarRow(
                    $row,
                    $headers,
                    $columnIndex,
                    $runId,
                    $hadWarning
                );
            } else {
                [$outputRow, $hadWarning] = $this->processGenericRow(
                    $row,
                    $headers,
                    $runId,
                    $hadWarning
                );
            }

            if ($hadWarning) {
                $rowsWithWarnings[] = $currentLine;
            }

            fwrite($output, $this->buildCsvLine($outputRow, $hasSpecificColumns) . "\n");
            $rowsProcessed++;
        }

        fclose($input);
        fclose($output);

        $logData = [
            'data_source' => $dataSourceCode,
            'original' => basename($originalCsvPath),
            'transformed' => basename($transformedCsvPath),
            'rows_processed' => $rowsProcessed,
        ];

        if (!empty($rowsWithWarnings)) {
            $logData['rows_with_backslash_warnings'] = count($rowsWithWarnings);
            $logData['sample_warning_lines'] = array_slice($rowsWithWarnings, 0, 10);

            Log::warning('⚠️  CSV transformado con advertencias: caracteres saneados', $logData);
        } else {
            Log::info('✅ CSV transformado sin advertencias', $logData);
        }

        return new SanitizedCsvResultDto(
            path: $transformedCsvPath,
            temporary: true
        );
    }

    /**
     * @param array<int, string|null> $row
     */
    private function isRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string|null> $row
     * @param array<int, string> $headers
     * @param array<string, int> $columnIndex
     *
     * @return array{0: array<int, string|null>, 1: bool}
     */
    private function processBascarRow(
        array $row,
        array $headers,
        array $columnIndex,
        int $runId,
        bool $hadWarning
    ): array {
        $jsonData = [];

        foreach ($headers as $index => $header) {
            $value = $row[$index] ?? null;

            if ($value !== null && is_string($value)) {
                $sanitized = str_replace('\\', ' ', $value);
                if ($sanitized !== $value) {
                    $hadWarning = true;
                }
                $value = $sanitized;
            }

            $jsonData[$header] = $value;
        }

        $valorTotalIndex = $columnIndex['VALOR_TOTAL_FACT'];
        $valorTotalValue = $row[$valorTotalIndex] ?? null;

        if ($valorTotalValue !== null) {
            $valorTotalValue = trim(str_replace('.', '', (string) $valorTotalValue));
            if ($valorTotalValue === '') {
                $valorTotalValue = null;
            }
        }

        $outputRow = [
            $runId,
            $this->sanitizeBackslashes($row[$columnIndex['NUM_TOMADOR']] ?? ''),
            $this->sanitizeBackslashes($row[$columnIndex['FECHA_INICIO_VIG']] ?? ''),
            $valorTotalValue,
            null,
            null,
            json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            null,
            null,
            '',
        ];

        return [$outputRow, $hadWarning];
    }

    /**
     * @param array<int, string|null> $row
     * @param array<int, string> $headers
     *
     * @return array{0: array<int, string|null>, 1: bool}
     */
    private function processGenericRow(
        array $row,
        array $headers,
        int $runId,
        bool $hadWarning
    ): array {
        $jsonData = [];

        foreach ($headers as $index => $header) {
            $value = $row[$index] ?? null;

            if ($value !== null && is_string($value)) {
                $sanitized = str_replace('\\', ' ', $value);
                if ($sanitized !== $value) {
                    $hadWarning = true;
                }
                $value = $sanitized;
            }

            $jsonData[$header] = $value;
        }

        $outputRow = [
            $runId,
            json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
        ];

        return [$outputRow, $hadWarning];
    }

    /**
     * @param array<int, string|null> $outputRow
     */
    private function buildCsvLine(array $outputRow, bool $hasSpecificColumns): string
    {
        $segments = [];

        foreach ($outputRow as $index => $value) {
            if ($value === null || $value === '') {
                $segments[] = '';
                continue;
            }

            $stringValue = (string) $value;
            $isJsonField = $hasSpecificColumns ? ($index === 6) : ($index === 1);

            if ($isJsonField) {
                $segments[] = '"' . str_replace('"', '""', $stringValue) . '"';
                continue;
            }

            if (str_contains($stringValue, ';')
                || str_contains($stringValue, "\n")
                || str_contains($stringValue, "\r")
            ) {
                $segments[] = '"' . str_replace('"', '""', $stringValue) . '"';
                continue;
            }

            $segments[] = $stringValue;
        }

        return implode(';', $segments);
    }

    /**
     * @param array<string, int> $columnIndex
     */
    private function assertBascarColumns(array $columnIndex, string $originalCsvPath): void
    {
        $required = ['NUM_TOMADOR', 'FECHA_INICIO_VIG', 'VALOR_TOTAL_FACT'];

        foreach ($required as $column) {
            if (!array_key_exists($column, $columnIndex)) {
                throw new RuntimeException(sprintf(
                    'CSV BASCAR sin columna requerida "%s" en archivo %s',
                    $column,
                    $originalCsvPath
                ));
            }
        }
    }

    private function sanitizeBackslashes(string $value): string
    {
        return str_replace('\\', ' ', $value);
    }
}
