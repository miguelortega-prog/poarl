<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recaudo\Comunicados;

use App\Models\CollectionNoticeRun;
use App\Models\CollectionNoticeRunResultFile;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class DownloadResultFileController
{
    public function __construct(
        private FilesystemFactory $filesystem
    ) {
    }

    /**
     * Descarga un archivo de resultados de un run.
     *
     * @param int $run
     * @param int $resultFile
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function __invoke(int $run, int $resultFile): StreamedResponse
    {
        // Buscar el run
        $runModel = CollectionNoticeRun::findOrFail($run);

        // Buscar el archivo de resultados
        $resultFileModel = CollectionNoticeRunResultFile::findOrFail($resultFile);

        // Validar que el archivo pertenece al run
        if ($resultFileModel->collection_notice_run_id !== $runModel->id) {
            abort(Response::HTTP_NOT_FOUND, 'Archivo no encontrado');
        }

        $disk = $this->filesystem->disk($resultFileModel->disk);

        // Validar que el archivo existe
        if (!$disk->exists($resultFileModel->path)) {
            abort(Response::HTTP_NOT_FOUND, 'Archivo no encontrado en el disco');
        }

        // Preparar headers para descarga
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $resultFileModel->file_name),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        // Retornar stream del archivo
        return response()->stream(
            function () use ($disk, $resultFileModel): void {
                $stream = $disk->readStream($resultFileModel->path);

                if ($stream === false) {
                    return;
                }

                while (!feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }

                fclose($stream);
            },
            Response::HTTP_OK,
            $headers
        );
    }
}
