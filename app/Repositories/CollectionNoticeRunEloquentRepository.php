<?php

namespace App\Repositories;

use App\Repositories\Interfaces\CollectionNoticeRunRepositoryInterface;
use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\Models\CollectionNoticeRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

    public function paginateWithRelations(int $perPage = 15): LengthAwarePaginator
    {
        return CollectionNoticeRun::query()
            ->with([
                'type:id,name',
                'requestedBy:id,name',
                'files:id,collection_notice_run_id,original_name,notice_data_source_id,uploaded_by,created_at',
                'files.dataSource:id,name',
                'files.uploader:id,name',
            ])
            ->latest('created_at')
            ->paginate($perPage);
    }
}
