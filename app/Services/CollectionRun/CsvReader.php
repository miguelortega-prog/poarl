<?php

declare(strict_types=1);

namespace App\Services\CollectionRun;

use Generator;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use RuntimeException;

/**
 * Servicio para leer archivos CSV usando streaming con Generators.
 *
 * Evita cargar archivos grandes en memoria completa.
 * Usa PHP Generators para procesamiento lazy y eficiente.
 *
 * Cumple con PSR-12 y tipado fuerte.
 */
final readonly class CsvReader
{
    public function __construct(
        private FilesystemFactory $filesystem
    ) {
    }

    /**
     * Lee un archivo CSV fila por fila usando streaming.
     *
     * @param string $filePath Ruta del archivo CSV
     * @param string $delimiter Delimitador de columnas (por defecto coma)
     * @param string $enclosure Caracter de enclosure (por defecto comillas dobles)
     * @param bool $skipHeader Si debe saltar la primera fila (encabezados)
     *
     * @return Generator<int, array<string, string|null>> Generator que produce filas como arrays asociativos
     *
     * @throws RuntimeException Si el archivo no existe o no se puede leer
     */
    public function readRows(
        string $filePath,
        string $delimiter = ',',
        string $enclosure = '"',
        bool $skipHeader = true
    ): Generator {
        $disk = $this->filesystem->disk('collection');

        if (!$disk->exists($filePath)) {
            throw new RuntimeException(
                sprintf('Archivo CSV no encontrado: %s', $filePath)
            );
        }

        $absolutePath = $disk->path($filePath);
        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            throw new RuntimeException(
                sprintf('No se pudo abrir el archivo CSV: %s', $filePath)
            );
        }

        try {
            $headers = null;
            $rowNumber = 0;

            while (($row = fgetcsv($handle, 0, $delimiter, $enclosure)) !== false) {
                $rowNumber++;

                // La primera fila son los encabezados
                if ($rowNumber === 1) {
                    $headers = array_map('trim', $row);

                    if ($skipHeader) {
                        continue;
                    }
                }

                // Combinar encabezados con valores
                $data = [];
                foreach ($headers as $index => $header) {
                    $data[$header] = isset($row[$index]) ? trim($row[$index]) : null;
                }

                yield $rowNumber => $data;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Lee un archivo CSV en chunks para procesamiento por lotes.
     *
     * @param string $filePath Ruta del archivo CSV
     * @param int $chunkSize Tamaño del chunk (número de filas)
     * @param string $delimiter Delimitador de columnas
     * @param string $enclosure Caracter de enclosure
     *
     * @return Generator<int, array<int, array<string, string|null>>> Generator que produce chunks de filas
     *
     * @throws RuntimeException Si el archivo no existe o no se puede leer
     */
    public function readInChunks(
        string $filePath,
        int $chunkSize = 1000,
        string $delimiter = ',',
        string $enclosure = '"'
    ): Generator {
        $chunk = [];
        $chunkIndex = 0;

        foreach ($this->readRows($filePath, $delimiter, $enclosure) as $rowNumber => $row) {
            $chunk[] = $row;

            if (count($chunk) >= $chunkSize) {
                yield $chunkIndex => $chunk;
                $chunk = [];
                $chunkIndex++;
            }
        }

        // Yield del último chunk si no está vacío
        if ($chunk !== []) {
            yield $chunkIndex => $chunk;
        }
    }

    /**
     * Cuenta el número de filas en un archivo CSV sin cargar todo en memoria.
     *
     * @param string $filePath Ruta del archivo CSV
     * @param bool $excludeHeader Si debe excluir la fila de encabezados del conteo
     *
     * @return int Número de filas
     *
     * @throws RuntimeException Si el archivo no existe o no se puede leer
     */
    public function countRows(string $filePath, bool $excludeHeader = true): int
    {
        $count = 0;

        foreach ($this->readRows($filePath, skipHeader: $excludeHeader) as $row) {
            $count++;
        }

        return $count;
    }
}
