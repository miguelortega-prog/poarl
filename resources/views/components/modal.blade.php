@props(['id' => null, 'maxWidth' => '2xl'])

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

    $wireModelProperty = null;

    foreach ($attributes->getAttributes() as $attributeKey => $attributeValue) {
        if (str_starts_with($attributeKey, 'wire:model')) {
            $wireModelProperty = $attributeValue;

            break;
        }
    }
@endphp

<div
    x-data='modalComponent({ property: @js($wireModelProperty) })'
    x-init="init()"
    x-on:close.stop="close()"
    x-on:keydown.escape.window="close()"
    x-show="show"
    x-cloak
    id="{{ $id }}"
    class="jetstream-modal fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50"
    style="display: none;"
>
    <div
        x-show="show"
        class="fixed inset-0 transform transition-all"
        x-on:click="close()"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="absolute inset-0 bg-gray-500 dark:bg-gray-900 opacity-75"></div>
    </div>

    <div
        x-show="show"
        class="mb-6 bg-white dark:bg-gray-800 rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"
        x-trap.inert.noscroll="show"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        {{ $slot }}
    </div>
</div>

@once
    <script>
        document.addEventListener('alpine:init', () => {
            const Alpine = window.Alpine;

            if (! Alpine) {
                return;
            }

            const readProperty = (component, property) => {
                if (! component || ! property) {
                    return undefined;
                }

                if (typeof component.get === 'function') {
                    return component.get(property);
                }

                if (component.$wire && typeof component.$wire.get === 'function') {
                    return component.$wire.get(property);
                }

                return undefined;
            };

            const setProperty = (component, property, value) => {
                if (! component || ! property) {
                    return;
                }

                if (typeof component.set === 'function') {
                    component.set(property, value);

                    return;
                }

                if (component.$wire && typeof component.$wire.set === 'function') {
                    component.$wire.set(property, value);
                }
            };

            const watchProperty = (component, property, callback) => {
                if (! component || ! property || typeof callback !== 'function') {
                    return null;
                }

                if (typeof component.watch === 'function') {
                    return component.watch(property, callback);
                }

                if (window.Livewire && typeof window.Livewire.hook === 'function') {
                    const release = window.Livewire.hook('message.processed', (message, instance) => {
                        if (instance.id !== component.id) {
                            return;
                        }

                        callback(readProperty(instance, property));
                    });

                    return () => {
                        if (typeof release === 'function') {
                            release();
                        }
                    };
                }

                return null;
            };

            const runMicrotask = (callback) => {
                if (typeof queueMicrotask === 'function') {
                    queueMicrotask(callback);

                    return;
                }

                Promise.resolve().then(callback);
            };

            Alpine.data('modalComponent', (config = {}) => ({
                show: false,
                property: config.property ?? null,
                livewireComponentId: null,
                livewire: null,
                syncingFromLivewire: false,
                removeWatcher: null,

                init() {
                    if (! this.property) {
                        return;
                    }

                    const parent = this.$root.closest('[wire\\:id]');

                    if (! parent) {
                        return;
                    }

                    this.livewireComponentId = parent.getAttribute('wire:id');

                    if (! this.livewireComponentId) {
                        return;
                    }

                    const attemptAttach = () => {
                        const component = window.Livewire && typeof window.Livewire.find === 'function'
                            ? window.Livewire.find(this.livewireComponentId)
                            : null;

                        if (! component) {
                            requestAnimationFrame(attemptAttach);

                            return;
                        }

                        this.attachTo(component);
                    };

                    attemptAttach();

                    this.$watch('show', (value) => {
                        if (this.syncingFromLivewire) {
                            return;
                        }

                        this.updateLivewire(value);
                    });

                    this.$root.addEventListener('alpine:destroy', () => {
                        if (typeof this.removeWatcher === 'function') {
                            this.removeWatcher();
                            this.removeWatcher = null;
                        }
                    });
                },

                attachTo(component) {
                    this.livewire = component;

                    this.syncingFromLivewire = true;
                    this.show = Boolean(readProperty(component, this.property));
                    runMicrotask(() => {
                        this.syncingFromLivewire = false;
                    });

                    const release = watchProperty(component, this.property, (value) => {
                        this.syncingFromLivewire = true;
                        this.show = Boolean(value);
                        runMicrotask(() => {
                            this.syncingFromLivewire = false;
                        });
                    });

                    if (typeof release === 'function') {
                        this.removeWatcher = release;
                    }
                },

                updateLivewire(value) {
                    if (! this.livewire || ! this.property) {
                        return;
                    }

                    const normalized = Boolean(value);
                    const current = Boolean(readProperty(this.livewire, this.property));

                    if (normalized === current) {
                        return;
                    }

                    setProperty(this.livewire, this.property, normalized);
                },

                close() {
                    this.show = false;
                },
            }));
        });
    </script>
@endonce
