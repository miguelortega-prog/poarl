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
            self::PENDING => 'bg-gray-500 text-white',
            self::VALIDATING => 'bg-secondary-900 text-primary-900',
            self::VALIDATED => 'bg-blue-600 text-white',
            self::PROCESSING => 'bg-secondary-900 text-primary-900',
            self::COMPLETED => 'bg-primary-900 text-white',
            self::FAILED => 'bg-red-600 text-white',
            self::CANCELLED => 'bg-red-600 text-white',
            self::VALIDATION_FAILED => 'bg-red-600 text-white',
        };
    }
}
