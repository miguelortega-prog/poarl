<?php

namespace App\Services\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\CreateRunFormDataDto;
use App\UseCases\Recaudo\Comunicados\CreateCollectionNoticeRunUseCase;

final class CreateCollectionNoticeRunSubmissionHandler
{
    public function __construct(private readonly CreateCollectionNoticeRunUseCase $useCase)
    {
    }

    public function handle(CreateRunFormDataDto $formData): mixed
    {
        $dto = $formData->toUseCaseDto();

        return ($this->useCase)($dto);
    }
}
