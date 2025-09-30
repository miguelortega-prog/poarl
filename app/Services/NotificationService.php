<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Servicio para gestionar notificaciones de usuarios con soporte de jerarquía.
 *
 * Principios SOLID aplicados:
 * - Single Responsibility: Solo maneja lógica de notificaciones
 * - Open/Closed: Extensible para agregar nuevos tipos de notificaciones
 *
 * Cumple con PSR-12 y tipado fuerte.
 */
final class NotificationService
{
    /**
     * Crea una nueva notificación para un usuario.
     *
     * @param array{user_id: int, created_by?: int|null, type: string, title: string, message: string, data?: array<string, mixed>|null, action_url?: string|null} $data
     */
    public function create(array $data): UserNotification
    {
        return UserNotification::create([
            'user_id' => $data['user_id'],
            'created_by' => $data['created_by'] ?? null,
            'type' => $data['type'],
            'title' => $data['title'],
            'message' => $data['message'],
            'data' => $data['data'] ?? null,
            'action_url' => $data['action_url'] ?? null,
        ]);
    }

    /**
     * Obtiene las notificaciones de un usuario (solo propias).
     *
     * @return Collection<int, UserNotification>
     */
    public function getUserNotifications(User $user, int $limit = 50, bool $unreadOnly = false): Collection
    {
        $query = UserNotification::query()
            ->where('user_id', $user->id)
            ->with(['user', 'creator'])
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($unreadOnly) {
            $query->unread();
        }

        return $query->get();
    }

    /**
     * Obtiene las notificaciones de un usuario incluyendo las de sus subordinados.
     * Los supervisores pueden ver notificaciones de su equipo.
     *
     * @return Collection<int, UserNotification>
     */
    public function getHierarchicalNotifications(User $user, int $limit = 50, bool $unreadOnly = false): Collection
    {
        // Obtener IDs de subordinados recursivamente
        $subordinateIds = $user->getAllSubordinateIds();

        // Incluir el usuario actual
        $userIds = array_merge([$user->id], $subordinateIds);

        $query = UserNotification::query()
            ->whereIn('user_id', $userIds)
            ->with(['user', 'creator'])
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($unreadOnly) {
            $query->unread();
        }

        return $query->get();
    }

    /**
     * Cuenta las notificaciones no leídas de un usuario (solo propias).
     */
    public function getUnreadCount(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->unread()
            ->count();
    }

    /**
     * Cuenta las notificaciones no leídas incluyendo subordinados.
     */
    public function getHierarchicalUnreadCount(User $user): int
    {
        $subordinateIds = $user->getAllSubordinateIds();
        $userIds = array_merge([$user->id], $subordinateIds);

        return UserNotification::query()
            ->whereIn('user_id', $userIds)
            ->unread()
            ->count();
    }

    /**
     * Marca una notificación como leída.
     */
    public function markAsRead(int $notificationId): bool
    {
        $notification = UserNotification::find($notificationId);

        if ($notification === null) {
            return false;
        }

        $notification->markAsRead();

        return true;
    }

    /**
     * Marca todas las notificaciones de un usuario como leídas.
     */
    public function markAllAsRead(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->unread()
            ->update(['read_at' => now()]);
    }

    /**
     * Elimina notificaciones antiguas (cleanup).
     * Por defecto elimina notificaciones leídas con más de 30 días.
     */
    public function cleanupOldNotifications(int $daysOld = 30): int
    {
        return UserNotification::query()
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays($daysOld))
            ->delete();
    }

    /**
     * Crea notificaciones para múltiples usuarios.
     *
     * @param array<int, int> $userIds
     * @param array{created_by?: int|null, type: string, title: string, message: string, data?: array<string, mixed>|null, action_url?: string|null} $data
     */
    public function createForMultipleUsers(array $userIds, array $data): int
    {
        $notifications = [];
        $now = now();

        foreach ($userIds as $userId) {
            $notifications[] = [
                'user_id' => $userId,
                'created_by' => $data['created_by'] ?? null,
                'type' => $data['type'],
                'title' => $data['title'],
                'message' => $data['message'],
                'data' => isset($data['data']) ? json_encode($data['data']) : null,
                'action_url' => $data['action_url'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('user_notifications')->insert($notifications);

        return count($notifications);
    }
}
