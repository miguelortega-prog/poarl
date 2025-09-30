<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recaudo\Comunicados;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCollectionRunValidation;
use App\Models\CollectionNoticeRun;
use Illuminate\Http\RedirectResponse;

final class RevalidateRunController extends Controller
{
    public function __invoke(CollectionNoticeRun $run): RedirectResponse
    {
        if (!in_array($run->status, ['validation_failed', 'pending'], true)) {
            return redirect()->back()->with('error', 'Solo se pueden re-validar comunicados con validación fallida o pendientes.');
        }

        if ($run->requested_by_id !== auth()->id()) {
            return redirect()->back()->with('error', 'No tienes permisos para re-validar este comunicado.');
        }

        $run->update([
            'status' => 'pending',
            'failed_at' => null,
        ]);

        ProcessCollectionRunValidation::dispatch($run->id);

        return redirect()->back()->with('success', 'La validación ha sido relanzada. Recibirás una notificación cuando termine.');
    }
}
