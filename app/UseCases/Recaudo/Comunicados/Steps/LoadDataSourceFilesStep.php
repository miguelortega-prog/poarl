<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use App\Services\CollectionRun\CsvReader;
use App\Services\Recaudo\DataSourceTableManager;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paso para cargar los archivos de insumos a base de datos.
 *
 * Lee los archivos CSV desde el disco y los carga en tablas de BD
 * para procesamiento eficiente con SQL.
 *
 * Nota: Los archivos Excel (PAGAPL, etc.) se cargan en sus propios steps
 * debido a su tamaño y complejidad.
 */
final readonly class LoadDataSourceFilesStep implements ProcessingStepInterface
{
    public function __construct(
        private FilesystemFactory $filesystem,
        private CsvReader $csvReader,
        private DataSourceTableManager $tableManager
    ) {
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return ProcessingContextDto
     */
    public function execute(ProcessingContextDto $context): ProcessingContextDto
    {
        $run = $context->run;
        $disk = $this->filesystem->disk('collection');

        $loadedData = [];
        $totalRowsLoaded = 0;

        foreach ($run->files as $file) {
            $dataSourceCode = $file->dataSource->code ?? 'unknown';
            $extension = strtolower($file->ext ?? '');

            Log::debug('Cargando archivo de insumo', [
                'run_id' => $run->id,
                'file_id' => $file->id,
                'data_source' => $dataSourceCode,
                'path' => $file->path,
            ]);

            if (!$disk->exists($file->path)) {
                throw new RuntimeException(
                    sprintf('Archivo no encontrado en disco: %s', $file->path)
                );
            }

            // Solo cargar archivos CSV a BD en este paso
            // Los Excel (PAGAPL, PAGPLA, DETTRA) se cargan en sus propios steps
            $isExcel = in_array($extension, ['xlsx', 'xls'], true);

            if ($isExcel) {
                // Para Excel, solo guardar metadata
                $loadedData[$dataSourceCode] = [
                    'file_id' => $file->id,
                    'data_source_id' => $file->notice_data_source_id,
                    'path' => $file->path,
                    'original_name' => $file->original_name,
                    'size' => $file->size,
                    'extension' => $extension,
                    'loaded_to_db' => false,
                    'is_excel' => true,
                ];

                continue;
            }

            // Cargar CSV a base de datos en chunks incrementales
            $delimiter = $this->detectDelimiter($dataSourceCode);

            Log::info('Cargando CSV a base de datos en chunks', [
                'run_id' => $run->id,
                'data_source' => $dataSourceCode,
                'file_path' => $file->path,
            ]);

            // Leer e insertar en chunks de 5000 filas (no cargar todo en memoria)
            $chunkSize = 5000;
            $chunk = [];
            $insertedCount = 0;
            $chunkNumber = 0;

            foreach ($this->csvReader->readRows($file->path, $delimiter) as $row) {
                $chunk[] = $row;

                // Cuando el chunk alcanza el tamaño máximo, insertar
                if (count($chunk) >= $chunkSize) {
                    $inserted = $this->tableManager->insertDataInChunks(
                        $dataSourceCode,
                        $run->id,
                        $chunk
                    );
                    $insertedCount += $inserted;
                    $chunkNumber++;

                    // Log cada 10 chunks
                    if ($chunkNumber % 10 === 0) {
                        Log::info('Progreso carga CSV', [
                            'run_id' => $run->id,
                            'data_source' => $dataSourceCode,
                            'chunks_processed' => $chunkNumber,
                            'rows_inserted' => $insertedCount,
                        ]);
                    }

                    // Limpiar chunk
                    $chunk = [];
                    gc_collect_cycles();
                }
            }

            // Insertar último chunk (filas restantes)
            if (!empty($chunk)) {
                $inserted = $this->tableManager->insertDataInChunks(
                    $dataSourceCode,
                    $run->id,
                    $chunk
                );
                $insertedCount += $inserted;
                unset($chunk);
            }

            $totalRowsLoaded += $insertedCount;

            Log::info('CSV cargado a BD exitosamente', [
                'run_id' => $run->id,
                'data_source' => $dataSourceCode,
                'total_rows' => $insertedCount,
            ]);

            $loadedData[$dataSourceCode] = [
                'file_id' => $file->id,
                'data_source_id' => $file->notice_data_source_id,
                'path' => $file->path,
                'original_name' => $file->original_name,
                'size' => $file->size,
                'extension' => $extension,
                'loaded_to_db' => true,
                'rows_count' => $insertedCount,
            ];
        }

        Log::info('Archivos de insumos cargados exitosamente', [
            'run_id' => $run->id,
            'files_count' => count($loadedData),
            'data_sources' => array_keys($loadedData),
            'total_rows_loaded' => $totalRowsLoaded,
        ]);

        return $context
            ->withData($loadedData)
            ->addStepResult($this->getName(), [
                'files_loaded' => count($loadedData),
                'data_sources' => array_keys($loadedData),
                'total_rows_loaded' => $totalRowsLoaded,
            ]);
    }

    /**
     * Detecta el delimitador correcto para un data source.
     *
     * @param string $dataSourceCode
     *
     * @return string
     */
    private function detectDelimiter(string $dataSourceCode): string
    {
        // BASCAR y otros CSV usan punto y coma
        return ';';
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Cargar archivos de insumos';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        return true;
    }
}
