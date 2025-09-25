<?php

namespace App\Repositories\Interfaces;

use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\Models\CollectionNoticeRun;

interface CollectionNoticeRunRepositoryInterface
{
    public function create(CreateCollectionNoticeRunDto $dto): CollectionNoticeRun;
}
