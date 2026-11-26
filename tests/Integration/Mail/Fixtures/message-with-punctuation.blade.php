<x-mail::message>
{{ $value }}

`literal \[ and \] and \\ and &#91; and &lt;`

`{{ $value }}`

<x-mail::panel>
{{ $value }}
</x-mail::panel>

<x-mail::button :url="$url">
Open
</x-mail::button>

<x-slot:subcopy>
{{ $value }}
</x-slot:subcopy>

<style>.punctuation::after { content: "{{ $punctuation }}"; }</style>

[Destination](https://{{ $host }}.good.test)

{{ $number }}

{!! e($link) !!}

{!! e($entities, false) !!}
</x-mail::message>
