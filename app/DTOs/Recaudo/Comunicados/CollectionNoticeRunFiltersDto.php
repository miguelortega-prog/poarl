<?php

namespace App\DTOs\Recaudo\Comunicados;

use Carbon\CarbonImmutable;

final class CollectionNoticeRunFiltersDto
{
    public function __construct(
        public readonly ?int $requestedById,
        public readonly ?int $collectionNoticeTypeId,
        public readonly ?CarbonImmutable $dateFrom,
        public readonly ?CarbonImmutable $dateTo,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function fromArray(array $filters): self
    {
        return new self(
            isset($filters['requested_by_id']) ? (int) $filters['requested_by_id'] : null,
            isset($filters['collection_notice_type_id']) ? (int) $filters['collection_notice_type_id'] : null,
            isset($filters['date_from']) && $filters['date_from'] !== null
                ? CarbonImmutable::parse($filters['date_from'])->startOfDay()
                : null,
            isset($filters['date_to']) && $filters['date_to'] !== null
                ? CarbonImmutable::parse($filters['date_to'])->endOfDay()
                : null,
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->requestedById !== null) {
            $query['requested_by_id'] = $this->requestedById;
        }

        if ($this->collectionNoticeTypeId !== null) {
            $query['collection_notice_type_id'] = $this->collectionNoticeTypeId;
        }

        if ($this->dateFrom !== null) {
            $query['date_from'] = $this->dateFrom->format('Y-m-d');
        }

        if ($this->dateTo !== null) {
            $query['date_to'] = $this->dateTo->format('Y-m-d');
        }

        return $query;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toViewData(): array
    {
        return [
            'requested_by_id' => $this->requestedById,
            'collection_notice_type_id' => $this->collectionNoticeTypeId,
            'date_from' => $this->dateFrom?->format('Y-m-d'),
            'date_to' => $this->dateTo?->format('Y-m-d'),
        ];
    }
}
