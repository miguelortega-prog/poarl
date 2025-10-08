<?php

namespace App\Repositories;

use App\DTOs\Recaudo\Comunicados\CollectionNoticeRunFiltersDto;
use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\Enums\Recaudo\CollectionNoticeRunStatus;
use App\Models\CollectionNoticeRun;
use App\Repositories\Interfaces\CollectionNoticeRunRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class CollectionNoticeRunEloquentRepository implements CollectionNoticeRunRepositoryInterface
{
    public function create(CreateCollectionNoticeRunDto $dto): CollectionNoticeRun
    {
        return CollectionNoticeRun::query()->create([
            'collection_notice_type_id' => $dto->collectionNoticeTypeId,
            'period'                    => $dto->periodValue,
            'requested_by_id'           => $dto->requestedById,
            'official_id'               => $dto->officialId,
            'status'                    => CollectionNoticeRunStatus::PENDING->value,
        ]);
    }

    public function findWithFiles(int $id): ?CollectionNoticeRun
    {
        return CollectionNoticeRun::query()
            ->with([
                'files:id,collection_notice_run_id,disk,path',
                'resultFiles:id,collection_notice_run_id,disk,path',
            ])
            ->find($id);
    }

    public function delete(CollectionNoticeRun $run): void
    {
        $run->delete();
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
                'resultFiles:id,collection_notice_run_id,file_type,file_name,records_count',
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
