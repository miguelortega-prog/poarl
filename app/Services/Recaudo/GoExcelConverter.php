<?php

declare(strict_types=1);

namespace App\Services\Recaudo;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Servicio para convertir archivos Excel (.xlsx) a CSV usando Go binario.
 *
 * Usa el binario optimizado `excel_to_csv` escrito en Go que es 8-10x más rápido
 * que PHP OpenSpout para archivos grandes (>100MB).
 *
 * Performance esperada: ~40 MB/s (vs ~5 MB/s con PHP OpenSpout)
 */
final class GoExcelConverter
{
    private const GO_BINARY_PATH = '/usr/local/bin/excel_streaming';

    /**
     * Convierte todas las hojas de un Excel a CSVs separados con columna sheet_name.
     *
     * @param Filesystem $disk Disco donde está el archivo
     * @param string $excelPath Ruta relativa del archivo Excel
     * @param string $outputDir Directorio donde guardar los CSVs
     * @param string $delimiter Delimitador del CSV (por defecto punto y coma)
     *
     * @return array{sheets: array<string, array{path: string, rows: int, size: int}>} Información de cada CSV generado
     *
     * @throws RuntimeException
     */
    public function convertAllSheetsToSeparateCSVs(
        Filesystem $disk,
        string $excelPath,
        string $outputDir,
        string $delimiter = ';'
    ): array {
        $startTime = microtime(true);

        if (!$disk->exists($excelPath)) {
            throw new RuntimeException(sprintf('Archivo Excel no encontrado: %s', $excelPath));
        }

        // Validar que el binario Go existe
        if (!file_exists(self::GO_BINARY_PATH)) {
            throw new RuntimeException(
                'Binario Go no encontrado en ' . self::GO_BINARY_PATH . '. ' .
                'Asegúrate de que el contenedor Docker esté correctamente construido.'
            );
        }

        $absoluteExcelPath = $disk->path($excelPath);
        $absoluteOutputDir = $disk->path($outputDir);
        $fileSize = $disk->size($excelPath);

        Log::info('🚀 Iniciando conversión ULTRA-RÁPIDA Excel→CSV con Go', [
            'excel_path' => $excelPath,
            'output_dir' => $outputDir,
            'size_mb' => round($fileSize / 1024 / 1024, 2),
            'go_binary' => self::GO_BINARY_PATH,
        ]);

        // Crear directorio de salida si no existe
        if (!$disk->exists($outputDir)) {
            $disk->makeDirectory($outputDir);
        }

        // Ejecutar binario Go con timeout de 10 minutos
        $result = Process::timeout(600)->run([
            self::GO_BINARY_PATH,
            '--input', $absoluteExcelPath,
            '--output', $absoluteOutputDir,
            '--delimiter', $delimiter,
        ]);

        if (!$result->successful()) {
            Log::error('Error ejecutando binario Go', [
                'excel_path' => $excelPath,
                'exit_code' => $result->exitCode(),
                'error_output' => $result->errorOutput(),
                'output' => $result->output(),
            ]);

            throw new RuntimeException(
                'Error al convertir Excel con Go: ' . $result->errorOutput()
            );
        }

        // Parsear JSON de salida del binario Go
        $output = json_decode($result->output(), true);

        if ($output === null || !isset($output['success'])) {
            throw new RuntimeException(
                'Respuesta inválida del binario Go: ' . $result->output()
            );
        }

        if (!$output['success']) {
            throw new RuntimeException(
                'Go converter falló: ' . ($output['error'] ?? 'Error desconocido')
            );
        }

        $phpDuration = (int) ((microtime(true) - $startTime) * 1000);
        $goDuration = $output['total_time_ms'];

        Log::info('✅ Conversión Go completada exitosamente', [
            'total_sheets' => count($output['sheets']),
            'total_rows' => $output['total_rows'],
            'go_time_ms' => $goDuration,
            'total_time_ms' => $phpDuration,
            'go_rows_per_second' => $goDuration > 0
                ? round($output['total_rows'] / ($goDuration / 1000))
                : 0,
            'mb_per_second' => $goDuration > 0
                ? round(($fileSize / 1024 / 1024) / ($goDuration / 1000), 2)
                : 0,
            'sheets' => array_map(fn($sheet) => [
                'name' => $sheet['name'],
                'rows' => $sheet['rows'],
                'duration_ms' => $sheet['duration_ms'],
            ], $output['sheets']),
        ]);

        // Convertir resultado Go al formato compatible con ExcelToCsvConverter
        $sheets = [];
        foreach ($output['sheets'] as $sheet) {
            // Convertir path absoluto a path relativo del disco
            $relativePath = str_replace($disk->path(''), '', $sheet['path']);

            $sheets[$sheet['name']] = [
                'path' => $relativePath,
                'rows' => $sheet['rows'],
                'size' => $sheet['size_kb'] * 1024,
            ];
        }

        return ['sheets' => $sheets];
    }

    /**
     * Verifica si el binario Go está disponible.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return file_exists(self::GO_BINARY_PATH) && is_executable(self::GO_BINARY_PATH);
    }

    /**
     * Obtiene la versión/info del binario Go.
     *
     * @return array{available: bool, path: string, executable: bool}
     */
    public static function getInfo(): array
    {
        $exists = file_exists(self::GO_BINARY_PATH);
        $executable = $exists && is_executable(self::GO_BINARY_PATH);

        return [
            'available' => $executable,
            'path' => self::GO_BINARY_PATH,
            'executable' => $executable,
        ];
    }
}
