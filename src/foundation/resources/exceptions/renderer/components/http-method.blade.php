@props(['method'])

@php
$type = match ($method) {
    'GET', 'OPTIONS', 'ANY' => 'default',
    'POST' => 'success',
    'PUT', 'PATCH' => 'primary',
    'DELETE' => 'error',
    default => 'default',
};
@endphp

<x-hypervel-exceptions-renderer::badge type="{{ $type }}">
    <x-hypervel-exceptions-renderer::icons.globe class="w-2.5 h-2.5" />
    {{ $method }}
</x-hypervel-exceptions-renderer::badge>
