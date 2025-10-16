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
        Log::info('Marcando run como completado', ['run_id' => $run->id]);

        $durationMs = null;
        if ($run->started_at) {
            $durationMs = (int) ($run->started_at->diffInMilliseconds(now()));
        }

        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'duration_ms' => $durationMs,
        ]);

        Log::info('Run marcado como completado', ['run_id' => $run->id]);
    }
}
