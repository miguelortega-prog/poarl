@props(['id' => null, 'maxWidth' => '3xl'])

@php
    $map = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
        '3xl' => 'sm:max-w-3xl',
    ];
    $maxWidthClass = $map[$maxWidth] ?? $map['3xl'];
@endphp

<div
    x-data="{ show: @entangle($attributes->wire('model')).live }"
    x-show="show"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    id="{{ $id ?? Str::random(32) }}"
    class="jetstream-modal fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50"
    style="display: none;"
>
    <!-- Overlay -->
    <div x-show="show" x-transition.opacity class="fixed inset-0 transform transition-all" x-on:click="show = false">
        <div class="absolute inset-0 bg-black/50"></div>
    </div>

    <!-- Panel -->
    <div x-show="show"
         x-transition
         x-trap.inert.noscroll="show"
         class="mb-6 bg-white dark:bg-gray-800 rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full {{ $maxWidthClass }} sm:mx-auto">
        <div class="px-6 py-4">
            {{ $title }}
        </div>

        <div class="px-6 py-4">
            {{ $content }}
        </div>

        <div class="px-6 py-4 bg-gray-100 dark:bg-gray-900/50">
            {{ $footer }}
        </div>
    </div>
</div>
