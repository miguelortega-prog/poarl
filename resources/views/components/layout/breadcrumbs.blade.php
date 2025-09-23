@php
    $path = trim(request()->path(), '/');
    $segments = $path === '' ? [] : explode('/', $path);

    $breadcrumbs = [];
    $accumulatedPath = '';

    foreach ($segments as $segment) {
        $accumulatedPath .= '/' . $segment;

        $label = (string) \Illuminate\Support\Str::of($segment)
            ->replace(['-', '_'], ' ')
            ->lower()
            ->ucwords();

        $breadcrumbs[] = [
            'label' => $label,
            'url' => url($accumulatedPath),
        ];
    }
@endphp

<nav class="mb-6" aria-label="Breadcrumb">
    <ol class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
        <li>
            <a href="{{ url('/') }}" class="font-medium transition hover:text-primary-700">
                {{ __('Inicio') }}
            </a>
        </li>

        @foreach ($breadcrumbs as $crumb)
            <li class="flex items-center gap-2">
                <span class="text-gray-400">/</span>

                @if ($loop->last)
                    <span class="font-semibold text-gray-900 dark:text-gray-100">
                        {{ $crumb['label'] }}
                    </span>
                @else
                    <a href="{{ $crumb['url'] }}" class="font-medium transition hover:text-primary-700">
                        {{ $crumb['label'] }}
                    </a>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
