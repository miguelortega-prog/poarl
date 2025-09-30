<div class="relative" x-data="{ open: @entangle('showDropdown') }" @click.away="open = false">
    {{-- Botón de campana con badge --}}
    <button
        type="button"
        @click="open = !open"
        class="relative inline-flex items-center justify-center rounded-full p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900 transition"
        aria-label="{{ __('Notificaciones') }}"
    >
        <i class="fa-solid fa-bell text-lg"></i>

        @if($unreadCount > 0)
            <span class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-600 rounded-full min-w-[1.25rem]">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </button>

    {{-- Dropdown de notificaciones --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute right-0 z-50 mt-2 w-80 origin-top-right rounded-2xl bg-white dark:bg-gray-800 shadow-xl ring-1 ring-black/5 dark:ring-white/10"
        role="menu"
        aria-orientation="vertical"
    >
        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 px-4 py-3">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ __('Notificaciones') }}
            </h3>

            @if($unreadCount > 0)
                <button
                    type="button"
                    wire:click="markAllAsRead"
                    class="text-xs text-primary hover:text-primary-600 dark:text-primary-400 dark:hover:text-primary-300 font-medium transition"
                >
                    {{ __('Marcar todas como leídas') }}
                </button>
            @endif
        </div>

        {{-- Lista de notificaciones --}}
        <div class="max-h-96 overflow-y-auto">
            @forelse($notifications as $notification)
                <div
                    class="border-b border-gray-100 dark:border-gray-700 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition {{ $notification->isUnread() ? 'bg-blue-50/30 dark:bg-blue-900/10' : '' }}"
                >
                    <div class="flex gap-3">
                        {{-- Icono según tipo --}}
                        <div class="flex-shrink-0 mt-1">
                            @if(str_contains($notification->type, 'validated'))
                                <i class="fa-solid fa-circle-check text-green-600 text-lg"></i>
                            @elseif(str_contains($notification->type, 'failed'))
                                <i class="fa-solid fa-circle-xmark text-red-600 text-lg"></i>
                            @elseif(str_contains($notification->type, 'completed'))
                                <i class="fa-solid fa-circle-check text-blue-600 text-lg"></i>
                            @else
                                <i class="fa-solid fa-bell text-gray-600 text-lg"></i>
                            @endif
                        </div>

                        {{-- Contenido --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $notification->title }}
                                </p>

                                @if($notification->isUnread())
                                    <span class="inline-block w-2 h-2 bg-blue-600 rounded-full flex-shrink-0 mt-1"></span>
                                @endif
                            </div>

                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400 line-clamp-2">
                                {{ $notification->message }}
                            </p>

                            {{-- Usuario destinatario (si es jerárquica) --}}
                            @if($notification->user_id !== auth()->id())
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
                                    <i class="fa-solid fa-user text-[10px]"></i>
                                    {{ $notification->user->name }}
                                </p>
                            @endif

                            <div class="mt-2 flex items-center gap-3">
                                <span class="text-xs text-gray-400 dark:text-gray-500">
                                    {{ $notification->created_at->diffForHumans() }}
                                </span>

                                @if($notification->isUnread())
                                    <button
                                        type="button"
                                        wire:click="markAsRead({{ $notification->id }})"
                                        class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                    >
                                        {{ __('Marcar como leída') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-4 py-8 text-center">
                    <i class="fa-solid fa-bell-slash text-gray-300 dark:text-gray-600 text-3xl mb-2"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('No tienes notificaciones') }}
                    </p>
                </div>
            @endforelse
        </div>

        {{-- Footer --}}
        {{--
        @if($notifications->isNotEmpty())
            <div class="border-t border-gray-200 dark:border-gray-700 px-4 py-3 text-center">
                <a
                    href="{{ route('notifications.index') }}"
                    class="text-sm text-primary hover:text-primary-600 dark:text-primary-400 dark:hover:text-primary-300 font-medium"
                    @click="open = false"
                >
                    {{ __('Ver todas las notificaciones') }}
                </a>
            </div>
        @endif
        --}}
    </div>
</div>
