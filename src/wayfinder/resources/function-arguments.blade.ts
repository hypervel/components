@if ($parameters->isNotEmpty())
{!! $args !!}{!! when($parameters->every->optional, '?') !!}: {
    @foreach ($parameters as $parameter)
        {{ $parameter->name }}{!! when($parameter->optional, '?') !!}: {!! $parameter->types !!}{!! when($parameter->optional, ' | null') !!}
        @if ($parameter->key)
            | { {!! $parameter->keyName() !!}: {!! $parameter->types !!} }
        @endif,
    @endforeach
}

| [
    @foreach ($parameters as $parameter)
        {{ $parameter->safeName() }}{!! when(
            $parameters->slice($loop->index)->every->optional,
            '?',
        ) !!}: {!! $parameter->types !!}{!! when($parameter->optional, ' | null') !!}
        @if ($parameter->key)
            | { {!! $parameter->keyName() !!}: {!! $parameter->types !!} }
         @endif
        {!! when(!$loop->last, ', ') !!}
    @endforeach
]

@if ($parameters->count() === 1) | {!! $parameters->first()->types !!}{!! when($parameters->first()->optional, ' | null') !!}
    @if($parameters->first()->key) | { {!! $parameters->first()->keyName() !!}: {!! $parameters->first()->types !!} }@endif
@endif
,
@endif
{!! $options !!}?: RouteQueryOptions
