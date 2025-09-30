<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Componente Livewire para dropdown de notificaciones.
 *
 * Muestra notificaciones del usuario y sus subordinados si es supervisor.
 * Cumple con PSR-12 y tipado fuerte.
 */
class NotificationsDropdown extends Component
{
    /**
     * Controla la visibilidad del dropdown.
     */
    public bool $showDropdown = false;

    /**
     * Notificaciones a mostrar.
     *
     * @var Collection<int, \App\Models\UserNotification>
     */
    public Collection $notifications;

    /**
     * Conteo de notificaciones no leídas.
     */
    public int $unreadCount = 0;

    /**
     * Si se deben mostrar notificaciones jerárquicas.
     */
    public bool $showHierarchical = true;

    /**
     * Listeners de eventos Livewire.
     *
     * @var array<string, string>
     */
    protected $listeners = [
        'notificationCreated' => 'refreshNotifications',
        'notificationRead' => 'refreshNotifications',
    ];

    /**
     * Monta el componente y carga las notificaciones.
     */
    public function mount(NotificationService $notificationService): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->notifications = new Collection();
            $this->unreadCount = 0;
            return;
        }

        $this->loadNotifications($notificationService, $user);
    }

    /**
     * Renderiza el componente.
     */
    public function render()
    {
        return view('livewire.notifications-dropdown');
    }

    /**
     * Alterna la visibilidad del dropdown.
     */
    public function toggleDropdown(): void
    {
        $this->showDropdown = !$this->showDropdown;
    }

    /**
     * Cierra el dropdown.
     */
    public function closeDropdown(): void
    {
        $this->showDropdown = false;
    }

    /**
     * Marca una notificación como leída.
     */
    public function markAsRead(int $notificationId, NotificationService $notificationService): void
    {
        $notificationService->markAsRead($notificationId);
        $this->refreshNotifications($notificationService);

        $this->dispatch('notificationRead');
    }

    /**
     * Marca todas las notificaciones como leídas.
     */
    public function markAllAsRead(NotificationService $notificationService): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $notificationService->markAllAsRead($user);
        $this->refreshNotifications($notificationService);

        $this->dispatch('notificationsRead');
    }

    /**
     * Refresca las notificaciones.
     */
    public function refreshNotifications(NotificationService $notificationService): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->notifications = new Collection();
            $this->unreadCount = 0;
            return;
        }

        $this->loadNotifications($notificationService, $user);
    }

    /**
     * Carga las notificaciones desde el servicio.
     */
    private function loadNotifications(NotificationService $notificationService, $user): void
    {
        if ($this->showHierarchical) {
            $this->notifications = $notificationService->getHierarchicalNotifications($user, 20);
            $this->unreadCount = $notificationService->getHierarchicalUnreadCount($user);
        } else {
            $this->notifications = $notificationService->getUserNotifications($user, 20);
            $this->unreadCount = $notificationService->getUnreadCount($user);
        }
    }
}
