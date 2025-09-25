<?php

namespace App\Http\Controllers;

use App\UseCases\Recaudo\Comunicados\ListCollectionNoticeRunsUseCase;
use Illuminate\Contracts\View\View;

final class CollectionNoticeRunsController extends Controller
{
    public function __construct(
        private readonly ListCollectionNoticeRunsUseCase $listCollectionNoticeRuns,
    ) {
    }

    public function index(): View
    {
        $runs = ($this->listCollectionNoticeRuns)();

        return view('recaudo.comunicados.index', [
            'runs' => $runs,
        ]);
    }
}
