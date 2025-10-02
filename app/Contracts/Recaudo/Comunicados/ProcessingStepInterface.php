<?php

declare(strict_types=1);

namespace App\Contracts\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;

/**
 * Interfaz para pasos individuales del pipeline de procesamiento.
 *
 * Cada paso recibe un contexto con datos y puede modificarlo
 * para los siguientes pasos.
 */
interface ProcessingStepInterface
{
    /**
     * Ejecuta el paso del procesamiento.
     *
     * @param ProcessingContextDto $context Contexto con datos del procesamiento
     *
     * @return ProcessingContextDto Contexto actualizado
     *
     * @throws \RuntimeException Si el paso falla
     */
    public function execute(ProcessingContextDto $context): ProcessingContextDto;

    /**
     * Retorna el nombre descriptivo del paso.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Indica si el paso debe ejecutarse basado en el contexto actual.
     *
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool;
}
