<?php

declare(strict_types=1);

namespace App\Contracts\Recaudo\Comunicados;

use App\Models\CollectionNoticeRun;

/**
 * Interfaz para procesadores de comunicados de recaudo.
 *
 * Cada tipo de comunicado implementa esta interfaz para definir
 * su lógica específica de procesamiento de datos.
 */
interface CollectionNoticeProcessorInterface
{
    /**
     * Procesa un run de comunicado ejecutando su pipeline de pasos.
     *
     * @param CollectionNoticeRun $run El run a procesar
     *
     * @return void
     *
     * @throws \RuntimeException Si el procesamiento falla
     */
    public function process(CollectionNoticeRun $run): void;

    /**
     * Retorna el nombre descriptivo del procesador.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Valida que el run puede ser procesado por este procesador.
     *
     * @param CollectionNoticeRun $run
     *
     * @return bool
     */
    public function canProcess(CollectionNoticeRun $run): bool;
}
