<?php

namespace App\Repositories\Interfaces;

use App\DTOs\Recaudo\Comunicados\RunStoredFileDto;
use App\Models\CollectionNoticeRunFile;

interface CollectionNoticeRunFileRepositoryInterface
{
    public function create(int $runId, RunStoredFileDto $file): CollectionNoticeRunFile;
}
