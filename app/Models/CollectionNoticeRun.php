<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo para CollectionNoticeRun.
 *
 * @property int $id
 * @property int|null $collection_notice_type_id
 * @property int|null $requested_by_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $validated_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property int|null $duration_ms
 * @property string $status
 * @property array|null $errors
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CollectionNoticeRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'collection_notice_type_id',
        'requested_by_id',
        'started_at',
        'validated_at',
        'completed_at',
        'failed_at',
        'duration_ms',
        'status',
        'errors',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'validated_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'duration_ms' => 'integer',
        'errors' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Estados posibles del run.
     */
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_VALIDATING = 'validating';
    public const string STATUS_VALIDATION_FAILED = 'validation_failed';
    public const string STATUS_VALIDATED = 'validated';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_CANCELLED = 'cancelled';

    // Relaciones

    public function type(): BelongsTo
    {
        return $this->belongsTo(CollectionNoticeType::class, 'collection_notice_type_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CollectionNoticeRunFile::class);
    }

    // Scopes

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeStatus($query, string $status): mixed
    {
        return $query->where('status', $status);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopePending($query): mixed
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeValidating($query): mixed
    {
        return $query->where('status', self::STATUS_VALIDATING);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeValidated($query): mixed
    {
        return $query->where('status', self::STATUS_VALIDATED);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeProcessing($query): mixed
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeCompleted($query): mixed
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeFailed($query): mixed
    {
        return $query->whereIn('status', [self::STATUS_VALIDATION_FAILED, self::STATUS_FAILED]);
    }

    // Métodos helper

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isValidating(): bool
    {
        return $this->status === self::STATUS_VALIDATING;
    }

    public function isValidated(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return in_array($this->status, [self::STATUS_VALIDATION_FAILED, self::STATUS_FAILED], true);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeValidated(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_VALIDATION_FAILED], true);
    }

    public function canBeProcessed(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    public function canBeCancelled(): bool
    {
        return !in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }
}
