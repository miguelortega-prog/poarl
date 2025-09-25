<?php

namespace App\Http\Controllers;

use App\DTOs\Recaudo\Comunicados\CollectionNoticeRunFiltersDto;
use App\Http\Requests\Recaudo\CollectionNoticeRunIndexRequest;
use App\Models\CollectionNoticeType;
use App\Models\User;
use App\UseCases\Recaudo\Comunicados\ListCollectionNoticeRunsUseCase;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

final class CollectionNoticeRunsController extends Controller
{
    public function __construct(
        private readonly ListCollectionNoticeRunsUseCase $listCollectionNoticeRuns,
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
