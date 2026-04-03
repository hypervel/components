@props(['frame'])

<div class="grid gap-3 p-4 bg-neutral-50 dark:bg-transparent overflow-x-auto rounded-lg">
    @if($frame->previous())
        <div class="flex">
            <x-hypervel-exceptions-renderer::formatted-source :$frame className="text-xs" />
        </div>
    @else
        <span class="font-mono text-xs leading-3 text-neutral-500">Entrypoint</span>
    @endif

    <x-hypervel-exceptions-renderer::file-with-line :$frame class="text-xs" />
</div>
