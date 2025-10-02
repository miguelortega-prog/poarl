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
     * @param CollectionNoticeRun $run
     * @param CollectionNoticeRunResultFile $file
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function __invoke(CollectionNoticeRun $run, CollectionNoticeRunResultFile $file): StreamedResponse
    {
        // Validar que el archivo pertenece al run
        if ($file->collection_notice_run_id !== $run->id) {
            abort(Response::HTTP_NOT_FOUND, 'Archivo no encontrado');
        }

        $disk = $this->filesystem->disk($file->disk);

        // Validar que el archivo existe
        if (!$disk->exists($file->path)) {
            abort(Response::HTTP_NOT_FOUND, 'Archivo no encontrado en el disco');
        }

        // Preparar headers para descarga
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $file->file_name),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        // Retornar stream del archivo
        return response()->stream(
            function () use ($disk, $file): void {
                $stream = $disk->readStream($file->path);

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
