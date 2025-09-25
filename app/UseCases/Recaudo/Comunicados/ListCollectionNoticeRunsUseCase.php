<?php

namespace App\UseCases\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\CollectionNoticeRunFiltersDto;
use App\Repositories\Interfaces\CollectionNoticeRunRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListCollectionNoticeRunsUseCase
{
    public function __construct(
        private readonly CollectionNoticeRunRepositoryInterface $repository,
    ) {
    }

    public function __invoke(CollectionNoticeRunFiltersDto $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginateWithRelations($filters, $perPage);
    }
}
