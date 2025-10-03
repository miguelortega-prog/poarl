<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Recaudo\Comunicados\CollectionNoticeProcessorInterface;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Service Provider para procesadores de comunicados de recaudo.
 *
 * Registra y valida todos los procesadores configurados en el sistema.
 */
final class CollectionNoticeServiceProvider extends ServiceProvider
{
    /**
     * Registra los servicios en el contenedor.
     *
     * @return void
     */
    public function register(): void
    {
        // Registrar procesadores como singletons
        $this->registerProcessors();
    }

    /**
     * Inicializa los servicios después del registro.
     *
     * @return void
     */
    public function boot(): void
    {
        // Validación de procesadores deshabilitada temporalmente
        // debido a problemas de permisos en WSL/Docker
        // TODO: Investigar y resolver problema de permisos

        // if ($this->app->environment('local', 'testing')) {
        //     $this->validateProcessorsConfiguration();
        // }
    }

    /**
     * Registra todos los procesadores configurados en el contenedor.
     *
     * @return void
     */
    private function registerProcessors(): void
    {
        $processors = config('collection-notices.processors', []);

        foreach ($processors as $type => $processorClass) {
            // Registrar cada procesador como singleton
            $this->app->singleton($processorClass);
        }
    }

    /**
     * Valida que todos los procesadores configurados sean válidos.
     *
     * @return void
     *
     * @throws RuntimeException Si la configuración es inválida
     */
    private function validateProcessorsConfiguration(): void
    {
        $processors = config('collection-notices.processors', []);

        if ($processors === []) {
            Log::warning('No hay procesadores configurados en collection-notices.processors');

            return;
        }

        foreach ($processors as $type => $processorClass) {
            // Validar que la clase existe
            if (!class_exists($processorClass)) {
                throw new RuntimeException(
                    sprintf(
                        'La clase del procesador "%s" para el tipo "%s" no existe',
                        $processorClass,
                        $type
                    )
                );
            }

            // Validar que implementa la interfaz correcta
            $reflection = new \ReflectionClass($processorClass);

            if (!$reflection->implementsInterface(CollectionNoticeProcessorInterface::class)) {
                throw new RuntimeException(
                    sprintf(
                        'El procesador "%s" debe implementar %s',
                        $processorClass,
                        CollectionNoticeProcessorInterface::class
                    )
                );
            }

            // Validar que es instanciable
            if (!$reflection->isInstantiable()) {
                throw new RuntimeException(
                    sprintf(
                        'El procesador "%s" no es instanciable (puede ser abstracto o una interfaz)',
                        $processorClass
                    )
                );
            }
        }

        Log::info('Configuración de procesadores validada correctamente', [
            'processors_count' => count($processors),
        ]);
    }
}

