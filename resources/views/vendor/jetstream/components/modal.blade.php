@props(['id' => null, 'maxWidth' => '2xl'])

@include('components.modal', [
    'id' => $id,
    'maxWidth' => $maxWidth,
    'attributes' => $attributes,
    'slot' => $slot,
])
