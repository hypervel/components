<!DOCTYPE html>
<html>
<head><title>Bench</title></head>
<body>
<ul>
@foreach ($items as $item)
    <li>{{ $item->id }}: {{ $item->name }}</li>
@endforeach
</ul>
<p>{{ implode(',', array_map(fn (?string $value): string => $value ?? 'null', $values)) }}</p>
<p id="bench-ok">{{ $count }}</p>
</body>
</html>
