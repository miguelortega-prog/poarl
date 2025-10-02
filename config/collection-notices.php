<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Collection Notice Processors
    |--------------------------------------------------------------------------
    |
    | Mapeo de identificadores de procesadores a casos de uso.
    | Cada tipo de comunicado tiene un procesador específico que implementa
    | su lógica de negocio.
    |
    */

    'processors' => [
        // Constitución en mora
        'constitucion_mora_aportantes' => \App\UseCases\Recaudo\Comunicados\Processors\ConstitucionMoraAportantesProcessor::class,

        // Avisos de incumplimiento (implementado como ejemplo)
        'aviso_incumplimiento_estados_cuenta' => \App\UseCases\Recaudo\Comunicados\Processors\AvisoIncumplimientoEstadosCuentaProcessor::class,

        // TODO: Implementar los demás procesadores según se necesiten
        // 'constitucion_mora_independientes' => \App\UseCases\Recaudo\Comunicados\Processors\ConstitucionMoraIndependientesProcessor::class,
        // 'aviso_incumplimiento_aportantes' => \App\UseCases\Recaudo\Comunicados\Processors\AvisoIncumplimientoAportantesProcessor::class,
        // 'aviso_incumplimiento_inconsistencias' => \App\UseCases\Recaudo\Comunicados\Processors\AvisoIncumplimientoInconsistenciasProcessor::class,
        // 'aviso_incumplimiento_independientes' => \App\UseCases\Recaudo\Comunicados\Processors\AvisoIncumplimientoIndependientesProcessor::class,
        // 'aviso_ministerio_aportantes' => \App\UseCases\Recaudo\Comunicados\Processors\AvisoMinisterioAportantesProcessor::class,
        // 'titulo_ejecutivo_aportantes' => \App\UseCases\Recaudo\Comunicados\Processors\TituloEjecutivoAportantesProcessor::class,
        // 'primera_accion_persuasiva_aportantes' => \App\UseCases\Recaudo\Comunicados\Processors\PrimeraAccionPersuasivaAportantesProcessor::class,
        // 'segunda_accion_persuasiva_aportantes' => \App\UseCases\Recaudo\Comunicados\Processors\SegundaAccionPersuasivaAportantesProcessor::class,
    ],
];
