<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionNoticeRun extends Model
{
    use HasFactory;

    // Si tu tabla es `collection_notice_runs`, no necesitas $table.
    // protected $table = 'collection_notice_runs';

    protected $fillable = [
        'collection_notice_type_id',
        'requested_by_id',
        'started_at',
        'duration_ms',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(CollectionNoticeType::class, 'collection_notice_type_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    // Scopes útiles
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
