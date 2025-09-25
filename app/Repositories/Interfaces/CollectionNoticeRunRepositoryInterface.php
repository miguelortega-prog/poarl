<?php

namespace App\Repositories\Interfaces;

use App\DTOs\Recaudo\Comunicados\CollectionNoticeRunFiltersDto;
use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\Models\CollectionNoticeRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CollectionNoticeRunRepositoryInterface
{
    public function create(CreateCollectionNoticeRunDto $dto): CollectionNoticeRun;

    public function paginateWithRelations(CollectionNoticeRunFiltersDto $filters, int $perPage = 15): LengthAwarePaginator;
}
