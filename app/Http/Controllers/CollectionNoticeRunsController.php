<?php

namespace App\Http\Controllers;

use App\DTOs\Recaudo\Comunicados\CollectionNoticeRunFiltersDto;
use App\DTOs\Recaudo\Comunicados\DeleteCollectionNoticeRunDto;
use App\Http\Requests\Recaudo\CollectionNoticeRunDestroyRequest;
use App\Http\Requests\Recaudo\CollectionNoticeRunIndexRequest;
use App\Models\CollectionNoticeRun;
use App\Models\CollectionNoticeType;
use App\Models\User;
use App\UseCases\Recaudo\Comunicados\DeleteCollectionNoticeRunUseCase;
use App\UseCases\Recaudo\Comunicados\ListCollectionNoticeRunsUseCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

final class CollectionNoticeRunsController extends Controller
{
    public function __construct(
        private readonly ListCollectionNoticeRunsUseCase $listCollectionNoticeRuns,
        private readonly DeleteCollectionNoticeRunUseCase $deleteCollectionNoticeRun,
    ) {
    }

    public function index(CollectionNoticeRunIndexRequest $request): View
    {
        $filtersDto = CollectionNoticeRunFiltersDto::fromArray($request->validated());

        $runs = ($this->listCollectionNoticeRuns)($filtersDto);

        return view('recaudo.comunicados.index', [
            'runs' => $runs,
            'filters' => $filtersDto->toViewData(),
            'requesters' => $this->requesters(),
            'types' => $this->noticeTypes(),
        ]);
    }

    public function destroy(CollectionNoticeRunDestroyRequest $request, CollectionNoticeRun $run): RedirectResponse
    {
        try {
            ($this->deleteCollectionNoticeRun)(new DeleteCollectionNoticeRunDto($run->id));

            return redirect()
                ->route('recaudo.comunicados.index')
                ->with('flash.banner', __('Comunicado eliminado correctamente.'))
                ->with('flash.bannerStyle', 'success');
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('recaudo.comunicados.index')
                ->with('flash.banner', $exception->getMessage())
                ->with('flash.bannerStyle', 'danger');
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('recaudo.comunicados.index')
                ->with('flash.banner', __('Ocurrió un error al intentar eliminar el comunicado.'))
                ->with('flash.bannerStyle', 'danger');
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function requesters(): Collection
    {
        return User::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, CollectionNoticeType>
     */
    private function noticeTypes(): Collection
    {
        return CollectionNoticeType::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
    }
}
