<?php

namespace App\Repositories;

use App\DTOs\Recaudo\Comunicados\CollectionNoticeRunFiltersDto;
use App\Repositories\Interfaces\CollectionNoticeRunRepositoryInterface;
use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\Models\CollectionNoticeRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class CollectionNoticeRunEloquentRepository implements CollectionNoticeRunRepositoryInterface
{
    public function create(CreateCollectionNoticeRunDto $dto): CollectionNoticeRun
    {
        return CollectionNoticeRun::query()->create([
            'collection_notice_type_id' => $dto->collectionNoticeTypeId,
            'period_value'              => $dto->periodValue,
            'requested_by'              => $dto->requestedBy,
            'status'                    => 'ready',
        ]);
    }

    public function paginateWithRelations(CollectionNoticeRunFiltersDto $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = CollectionNoticeRun::query()
            ->with([
                'type:id,name',
                'requestedBy:id,name',
                'files:id,collection_notice_run_id,original_name,notice_data_source_id,uploaded_by,created_at',
                'files.dataSource:id,name',
                'files.uploader:id,name',
            ]);

        $this->applyFilters($query, $filters);

        return $query
            ->latest('created_at')
            ->paginate($perPage)
            ->appends($filters->toQuery());
    }

    private function applyFilters(Builder $query, CollectionNoticeRunFiltersDto $filters): void
    {
        if ($filters->requestedById !== null) {
            $query->where('requested_by_id', $filters->requestedById);
        }

        if ($filters->collectionNoticeTypeId !== null) {
            $query->where('collection_notice_type_id', $filters->collectionNoticeTypeId);
        }

        if ($filters->dateFrom !== null) {
            $query->where('created_at', '>=', $filters->dateFrom);
        }

        if ($filters->dateTo !== null) {
            $query->where('created_at', '<=', $filters->dateTo);
        }
    }
}
