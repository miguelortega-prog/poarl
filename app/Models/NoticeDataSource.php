<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NoticeDataSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',        // <- necesario para updateOrCreate() en seeding
        'name',
        'num_columns',
    ];

    protected $casts = [
        'num_columns' => 'integer',
    ];

    // Normalizaciones
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn ($v) => strtoupper(trim($v))
        );
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn ($v) => trim($v)
        );
    }

    // Relaciones
    public function columns(): HasMany
    {
        return $this->hasMany(NoticeDataSourceColumn::class)
                    ->orderBy('column_name');
    }

    public function noticeTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            CollectionNoticeType::class,
            'collection_notice_type_data_sources',
            'notice_data_source_id',
            'collection_notice_type_id'
        )->withTimestamps();
    }

    public function scopeCode($query, string $code)
    {
        return $query->where('code', strtoupper($code));
    }
}
