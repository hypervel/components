<?php

declare(strict_types=1);

use Hypervel\Support\ClassMetadataCache;
use Hypervel\Support\Reflector;

use function PHPStan\Testing\assertType;

assertType('ReflectionTypeLazyClass', lazy(
    ReflectionTypeLazyClass::class,
    fn (ReflectionTypeLazyClass $instance) => []
));
assertType('ReflectionTypeLazyClass', lazy(fn (ReflectionTypeLazyClass $instance) => []));

assertType('ReflectionTypeLazyClass', proxy(
    ReflectionTypeLazyClass::class,
    function (ReflectionTypeLazyClass $proxy, array $eager) {
        assertType('ReflectionTypeLazyClass', $proxy);
        assertType('array<string, mixed>', $eager);

        return new ReflectionTypeLazyClass;
    }
));
assertType('ReflectionTypeLazyClass', proxy(
    fn (ReflectionTypeLazyClass $proxy) => new ReflectionTypeLazyClass
));
assertType('ReflectionTypeLazyClass', proxy(fn (): ReflectionTypeLazyClass => new ReflectionTypeLazyClass));

assertType(
    'ReflectionTypeAttribute|null',
    Reflector::getClassAttribute(ReflectionTypeTarget::class, ReflectionTypeAttribute::class)
);
assertType(
    'Hypervel\Support\Collection<int, ReflectionTypeAttribute>',
    Reflector::getClassAttributes(new ReflectionTypeTarget, ReflectionTypeAttribute::class)
);
assertType(
    'Hypervel\Support\Collection<class-string<ReflectionTypeTarget>, Hypervel\Support\Collection<int, ReflectionTypeAttribute>>',
    Reflector::getClassAttributes(ReflectionTypeTarget::class, ReflectionTypeAttribute::class, true)
);

assertType('ReflectionClass<object>', ClassMetadataCache::reflectClass(ReflectionTypeTarget::class));
assertType('ReflectionClass<object>', ClassMetadataCache::reflectClass(new ReflectionTypeTarget));
assertType('ReflectionMethod', ClassMetadataCache::reflectMethod(ReflectionTypeTarget::class, 'method'));
assertType('ReflectionMethod', ClassMetadataCache::reflectMethod(new ReflectionTypeTarget, 'method'));

class ReflectionTypeLazyClass
{
}

#[Attribute]
class ReflectionTypeAttribute
{
}

#[ReflectionTypeAttribute]
class ReflectionTypeTarget
{
    public function method(): void
    {
    }
}
