<?php

namespace App\Repositories;

use App\Repositories\Interfaces\CollectionNoticeRunRepositoryInterface;
use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\Models\CollectionNoticeRun;

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
}
