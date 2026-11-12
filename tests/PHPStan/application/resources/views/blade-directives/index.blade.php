@extendsFirst(['blade-directives.layout'])

@includeWhen($mode === 'compact', 'blade-directives.when')
@includeUnless($mode === 'compact', 'blade-directives.unless', ['mode' => $mode])
@includeFirst(['blade-directives.first', 'blade-directives.first-fallback'])
@includeIsolated('blade-directives.isolated')
@each('blade-directives.item', $jobs, 'job', 'blade-directives.empty')
@each('blade-directives.item', $jobs, 'job', 'raw|No jobs')

@component('blade-directives.component')
@endcomponent
@componentFirst(['blade-directives.component-first'])
@endcomponentFirst

@@include('blade-directives.escaped')
{{-- @include('blade-directives.commented') <x-orphan /> --}}
@verbatim @include('blade-directives.verbatim') @endverbatim
