<?php

namespace App\DTOs\Recaudo\Comunicados;

final class DeleteCollectionNoticeRunDto
{
    public function __construct(
        public readonly int $runId,
    ) {
    }
}
