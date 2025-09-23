<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectionNoticeType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
    ];

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

    public function dataSources(): BelongsToMany
    {
        return $this->belongsToMany(
            NoticeDataSource::class,
            'collection_notice_type_data_sources',
            'collection_notice_type_id',
            'notice_data_source_id'
        )->withTimestamps();
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CollectionNoticeRun::class, 'collection_notice_type_id');
    }

    public function scopeCode($query, string $code)
    {
        return $query->where('code', strtoupper($code));
    }
}
