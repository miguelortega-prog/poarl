<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoticeDataSourceColumn extends Model
{
    use HasFactory;

    protected $fillable = [
        'notice_data_source_id',
        'column_name',
    ];

    // Opcional: actualiza updated_at del padre cuando cambie una columna
    protected $touches = ['dataSource'];

    // Normaliza espacios; NO cambies el case
    protected function columnName(): Attribute
    {
        return Attribute::make(
            set: fn ($v) => is_string($v) ? trim($v) : $v
        );
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(NoticeDataSource::class, 'notice_data_source_id');
    }

    // Azúcar útil si filtras seguido por fuente
    public function scopeForSource($query, int $sourceId)
    {
        return $query->where('notice_data_source_id', $sourceId);
    }
}
