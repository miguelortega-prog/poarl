<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Sanitizar campos numéricos en tablas de data sources.
 *
 * Limpia campos numéricos que vienen con formato europeo (separador de miles: punto, decimal: coma)
 * y los convierte al formato estándar para PostgreSQL (sin separador de miles, decimal: punto).
 *
 * Transformación:
 * - Entrada: "1.234.567,89" (formato europeo)
 * - Salida: "1234567.89" (formato estándar)
 *
 * Proceso:
 * 1. REPLACE(campo, '.', '') → Elimina puntos (separador de miles)
 * 2. REPLACE(resultado, ',', '.') → Convierte coma a punto (separador decimal)
 *
 * Este step debe ejecutarse DESPUÉS de cargar los datos y ANTES de cualquier transformación SQL
 * que requiera usar estos campos numéricos en cálculos.
 */
final class SanitizeNumericFieldsStep implements ProcessingStepInterface
{
    /**
     * Mapeo de tablas y sus columnas numéricas que requieren sanitización.
     *
     * Formato: 'tabla' => ['columna1', 'columna2', ...]
     */
    private const NUMERIC_FIELDS = [
        'data_source_bascar' => ['valor_total_fact'],
    ];

    public function getName(): string
    {
        return 'Sanitizar campos numéricos (formato europeo → estándar)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Sanitizando campos numéricos', ['run_id' => $run->id]);

        foreach (self::NUMERIC_FIELDS as $tableName => $columns) {
            $this->sanitizeTableFields($tableName, $columns, $run);
        }

        Log::info('Sanitización de campos numéricos completada', ['run_id' => $run->id]);
    }

    /**
     * Sanitiza campos numéricos de una tabla específica.
     *
     * Procesa registros por chunks para:
     * - Validar cada valor individualmente
     * - Convertir formato europeo a estándar
     * - Manejar errores de conversión
     * - Actualizar BD con valores limpios
     *
     * @param string $tableName Nombre de la tabla
     * @param array<string> $columns Lista de columnas a sanitizar
     * @param CollectionNoticeRun $run Run actual
     */
    private function sanitizeTableFields(string $tableName, array $columns, CollectionNoticeRun $run): void
    {
        $recordCount = DB::table($tableName)->where('run_id', $run->id)->count();

        if ($recordCount === 0) {
            return;
        }

        $chunkSize = 1000;
        $processed = 0;
        $errors = 0;

        DB::table($tableName)
            ->where('run_id', $run->id)
            ->orderBy('id')
            ->chunk($chunkSize, function ($records) use ($tableName, $columns, &$processed, &$errors) {
                foreach ($records as $record) {
                    $updates = [];

                    foreach ($columns as $column) {
                        $originalValue = $record->{$column} ?? null;

                        if ($originalValue === null || trim($originalValue) === '') {
                            continue;
                        }

                        // Sanitizar valor: eliminar espacios, puntos (miles), convertir coma a punto (decimal)
                        $sanitized = $this->sanitizeNumericValue($originalValue);

                        // Validar que el resultado sea numérico válido
                        if ($sanitized !== null && $sanitized !== $originalValue) {
                            $updates[$column] = $sanitized;
                        } elseif ($sanitized === null) {
                            $errors++;
                            Log::warning('Valor numérico inválido', [
                                'table' => $tableName,
                                'record_id' => $record->id,
                                'column' => $column,
                                'original_value' => $originalValue,
                            ]);
                        }
                    }

                    // Actualizar registro si hay cambios
                    if (!empty($updates)) {
                        DB::table($tableName)
                            ->where('id', $record->id)
                            ->update($updates);
                    }

                    $processed++;
                }
            });

        Log::info('Sanitización de campos numéricos en tabla completada', [
            'table' => $tableName,
            'columns' => $columns,
            'processed' => $processed,
            'errors' => $errors,
        ]);
    }

    /**
     * Sanitiza un valor numérico individual.
     *
     * En los datos, tanto el punto (.) como la coma (,) son separadores de miles.
     * NO hay valores decimales.
     *
     * Conversión:
     * - " 1.234.567 " → "1234567"
     * - "14,861" → "14861"
     * - "2,835,609" → "2835609"
     * - "  100  " → "100"
     *
     * @param string $value Valor original
     * @return string|null Valor sanitizado o null si es inválido
     */
    private function sanitizeNumericValue(string $value): ?string
    {
        // 1. Eliminar espacios al inicio y final
        $cleaned = trim($value);

        if ($cleaned === '') {
            return null;
        }

        // 2. Eliminar TODOS los separadores (punto y coma son separadores de miles)
        $cleaned = str_replace(['.', ','], '', $cleaned);

        // 3. Validar que sea numérico entero
        if (!is_numeric($cleaned)) {
            return null;
        }

        return $cleaned;
    }
}
