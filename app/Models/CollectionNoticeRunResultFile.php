<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para archivos de resultados de CollectionNoticeRun.
 *
 * @property int $id
 * @property int $collection_notice_run_id
 * @property string $file_type
 * @property string $file_name
 * @property string $disk
 * @property string $path
 * @property int $size
 * @property int $records_count
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class CollectionNoticeRunResultFile extends Model
{
    protected $fillable = [
        'collection_notice_run_id',
        'file_type',
        'file_name',
        'disk',
        'path',
        'size',
        'records_count',
        'metadata',
    ];

    protected $casts = [
        'size' => 'integer',
        'records_count' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relaciones

    public function run(): BelongsTo
    {
        return $this->belongsTo(CollectionNoticeRun::class, 'collection_notice_run_id');
    }

    /**
     * Configurar la clave de la ruta para este modelo.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
