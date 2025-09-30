<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de notificaciones de usuario.
 *
 * Cumple con PSR-12 y tipado fuerte.
 * Soporta jerarquía: supervisores pueden ver notificaciones de subordinados.
 */
class UserNotification extends Model
{
    /**
     * Nombre de la tabla.
     */
    protected $table = 'user_notifications';

    /**
     * Campos asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'created_by',
        'type',
        'title',
        'message',
        'data',
        'action_url',
        'read_at',
    ];

    /**
     * Tipos de datos para casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Usuario destinatario de la notificación.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Usuario que creó la notificación.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Marca la notificación como leída.
     */
    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    /**
     * Marca la notificación como no leída.
     */
    public function markAsUnread(): void
    {
        $this->update(['read_at' => null]);
    }

    /**
     * Verifica si la notificación ha sido leída.
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Verifica si la notificación no ha sido leída.
     */
    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Scope: solo notificaciones no leídas.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserNotification> $query
     * @return \Illuminate\Database\Eloquent\Builder<UserNotification>
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope: solo notificaciones leídas.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserNotification> $query
     * @return \Illuminate\Database\Eloquent\Builder<UserNotification>
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope: notificaciones por tipo.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserNotification> $query
     * @return \Illuminate\Database\Eloquent\Builder<UserNotification>
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
