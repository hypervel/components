<x-hypervel-exceptions-renderer::layout>
    <x-hypervel-exceptions-renderer::section-container class="px-6 py-0 sm:py-0">
        <x-hypervel-exceptions-renderer::topbar :title="$exception->title()" :markdown="$exceptionAsMarkdown" />
    </x-hypervel-exceptions-renderer::section-container>

    <x-hypervel-exceptions-renderer::separator />

    <x-hypervel-exceptions-renderer::section-container class="flex flex-col gap-8 py-0 sm:py-0">
        <x-hypervel-exceptions-renderer::header :$exception />
    </x-hypervel-exceptions-renderer::section-container>

    <x-hypervel-exceptions-renderer::separator class="-mt-5 -z-10" />

    <x-hypervel-exceptions-renderer::section-container class="flex flex-col gap-8 pt-14">
        <x-hypervel-exceptions-renderer::trace :$exception />

        @if ($exception->previousExceptions()->isNotEmpty())
            <x-hypervel-exceptions-renderer::previous-exceptions :$exception />
        @endif

        <x-hypervel-exceptions-renderer::query :queries="$exception->applicationQueries()" />
    </x-hypervel-exceptions-renderer::section-container>

    <x-hypervel-exceptions-renderer::separator />

    <x-hypervel-exceptions-renderer::section-container class="flex flex-col gap-12">
        <x-hypervel-exceptions-renderer::request-header :headers="$exception->requestHeaders()" />

        <x-hypervel-exceptions-renderer::request-body :body="$exception->requestBody()" />

        <x-hypervel-exceptions-renderer::routing :routing="$exception->applicationRouteContext()" />

        <x-hypervel-exceptions-renderer::routing-parameter :routeParameters="$exception->applicationRouteParametersContext()" />
    </x-hypervel-exceptions-renderer::section-container>

</x-hypervel-exceptions-renderer::layout>
