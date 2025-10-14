<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionNoticeRunFile extends Model
{
    protected $guarded = [];

    // Estados de importación
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $casts = [
        'import_started_at' => 'datetime',
        'import_completed_at' => 'datetime',
    ];

    /**
     * Marca el archivo como en proceso de importación.
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'import_status' => self::STATUS_PROCESSING,
            'import_started_at' => now(),
            'import_error' => null, // Limpiar errores previos
        ]);
    }

    /**
     * Marca el archivo como importado exitosamente.
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'import_status' => self::STATUS_COMPLETED,
            'import_completed_at' => now(),
            'import_error' => null,
        ]);
    }

    /**
     * Marca el archivo como fallido.
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'import_status' => self::STATUS_FAILED,
            'import_completed_at' => now(),
            'import_error' => $error,
        ]);
    }

    /**
     * Verifica si el archivo ya fue importado.
     */
    public function isCompleted(): bool
    {
        return $this->import_status === self::STATUS_COMPLETED;
    }

    /**
     * Verifica si el archivo está pendiente de importación.
     */
    public function isPending(): bool
    {
        return $this->import_status === self::STATUS_PENDING;
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CollectionNoticeRun::class, 'collection_notice_run_id');
    }
    
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(NoticeDataSource::class, 'notice_data_source_id');
    }
    
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }    
}
