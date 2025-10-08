<?php

declare(strict_types=1);

namespace App\Contracts\Recaudo\Comunicados;

use App\Models\CollectionNoticeRun;

/**
 * Interfaz para pasos individuales del pipeline de procesamiento.
 *
 * Cada paso recibe un run y ejecuta su lógica de procesamiento.
 */
interface ProcessingStepInterface
{
    /**
     * Ejecuta el paso del procesamiento.
     *
     * @param CollectionNoticeRun $run Run siendo procesado
     *
     * @return void
     *
     * @throws \RuntimeException Si el paso falla
     */
    public function execute(CollectionNoticeRun $run): void;

    /**
     * Retorna el nombre descriptivo del paso.
     *
     * @return string
     */
    public function getName(): string;
}
