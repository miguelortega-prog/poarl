<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\Log;

/**
 * Step: Marcar run como completado.
 *
 * Cambia el estado del run a 'completed' y registra la duración total del procesamiento.
 */
final class MarkRunAsCompletedStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Marcar run como completado';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('✅ Marcando run como completado', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Calcular duración total (desde started_at hasta ahora)
        $durationMs = null;
        if ($run->started_at) {
            $durationMs = (int) ($run->started_at->diffInMilliseconds(now()));
        }

        // Actualizar run a estado completed
        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'duration_ms' => $durationMs,
        ]);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Run marcado como completado', [
            'run_id' => $run->id,
            'total_duration_ms' => $durationMs,
            'total_duration_minutes' => $durationMs ? round($durationMs / 60000, 2) : null,
            'step_duration_ms' => $duration,
        ]);
    }
}
