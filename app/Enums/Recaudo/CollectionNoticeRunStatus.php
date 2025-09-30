<?php

declare(strict_types=1);

namespace App\Enums\Recaudo;

enum CollectionNoticeRunStatus: string
{
    case PENDING = 'pending';
    case VALIDATING = 'validating';
    case VALIDATION_FAILED = 'validation_failed';
    case VALIDATED = 'validated';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * Obtiene el label traducido del estado.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => __('Pendiente'),
            self::VALIDATING => __('Validando'),
            self::VALIDATION_FAILED => __('Validación fallida'),
            self::VALIDATED => __('Validado'),
            self::PROCESSING => __('Procesando'),
            self::COMPLETED => __('Completado'),
            self::FAILED => __('Fallido'),
            self::CANCELLED => __('Cancelado'),
        };
    }

    /**
     * Obtiene la clase CSS para el badge según el estado.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-gray-500',
            self::VALIDATING => 'bg-blue-500',
            self::VALIDATION_FAILED => 'bg-orange-500',
            self::VALIDATED => 'bg-green-500',
            self::PROCESSING => 'bg-indigo-500',
            self::COMPLETED => 'bg-green-700',
            self::FAILED => 'bg-red-500',
            self::CANCELLED => 'bg-gray-400',
        };
    }
}
