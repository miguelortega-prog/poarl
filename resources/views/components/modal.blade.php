@props(['id', 'maxWidth'])

@php
$id = $id ?? md5($attributes->wire('model'));

$availableMaxWidths = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
    '3xl' => 'sm:max-w-3xl',
];

$maxWidth = $availableMaxWidths[$maxWidth ?? '2xl'] ?? $availableMaxWidths['2xl'];

$wireModel = $attributes->wire('model');
$modelProperty = $wireModel ? (string) $wireModel : null;
@endphp

<div
    x-data="{
        show: false,
        property: @js($modelProperty),
        componentId: null,
        watchRegistered: false,
        commitHookBound: false,
        livewireComponent() {
            if (! this.property) {
                return null;
            }

            if (this.componentId && window.Livewire) {
                return window.Livewire.find(this.componentId);
            }

            const root = this.$el.closest('[wire\\:id]');

            if (! root || ! window.Livewire) {
                return null;
            }

            this.componentId = root.getAttribute('wire:id');

            return this.componentId ? window.Livewire.find(this.componentId) : null;
        },
        syncFromLivewire() {
            const component = this.livewireComponent();

            if (! component || ! this.property) {
                return;
            }

            this.show = Boolean(component.get(this.property));
        },
        init() {
            if (! this.property) {
                return;
            }

            const bind = () => {
                const component = this.livewireComponent();

                if (! component) {
                    requestAnimationFrame(bind);
                    return;
                }

                this.syncFromLivewire();

                if (! this.watchRegistered) {
                    this.$watch('show', (value) => {
                        const currentComponent = this.livewireComponent();

                        if (! currentComponent || ! this.property) {
                            return;
                        }

                        const current = Boolean(currentComponent.get(this.property));
                        const next = Boolean(value);

                        if (current === next) {
                            return;
                        }

                        currentComponent.set(this.property, next);
                    });

                    this.watchRegistered = true;
                }

                if (window.Livewire && typeof window.Livewire.hook === 'function' && ! this.commitHookBound) {
                    window.Livewire.hook('commit', (payload, component) => {
                        const target = component || (payload && payload.component) || null;

                        if (! target || target.id !== this.componentId) {
                            return;
                        }

                        this.syncFromLivewire();
                    });

                    this.commitHookBound = true;
                }
            };

            if (window.Livewire) {
                bind();
            } else {
                document.addEventListener('livewire:init', bind, { once: true });
            }
        },
        close() {
            this.show = false;
        },
    }"
    x-on:close.stop="close()"
    x-on:keydown.escape.window="close()"
    x-show="show"
    x-cloak
    id="{{ $id }}"
    class="jetstream-modal fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50"
    style="display: none;"
>
    <div x-show="show" class="fixed inset-0 transform transition-all" x-on:click="close()" x-transition:enter="ease-out duration-300"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 bg-gray-500 dark:bg-gray-900 opacity-75"></div>
    </div>

    <div x-show="show" class="mb-6 bg-white dark:bg-gray-800 rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"
                    x-trap.inert.noscroll="show"
                    x-transition:enter="ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
        {{ $slot }}
    </div>
</div>
