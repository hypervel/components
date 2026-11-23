<?php

declare(strict_types=1);

namespace InheritedMethodTemplates;

use Hypervel\Sentry\Hub;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

use function PHPStan\Testing\assertType;

/**
 * @param Collection<int, string> $eager
 * @param LazyCollection<int, string> $lazy
 */
function inheritedTemplates(Collection $eager, LazyCollection $lazy, Hub $hub, mixed $unknown, string $name): void
{
    // Hypervel: these overrides repeat their parent's method templates, which PHPStan 2.3.5
    // otherwise leaves unresolved when the arguments do not infer them.
    assertType('Hypervel\Support\Collection<(int|string), string>', $eager->keyBy(fn (string $value) => $unknown));
    assertType('Hypervel\Support\LazyCollection<(int|string), string>', $lazy->keyBy(fn (string $value) => $unknown));
    assertType('Hypervel\Support\LazyCollection<(int|string), array<int, mixed>>', $lazy->mapToDictionary(fn (string $value) => $unknown));
    assertType('Hypervel\Support\LazyCollection<int, array<int, mixed>>', $lazy->crossJoin($unknown));
    assertType('Hypervel\Support\LazyCollection<int, mixed>', $lazy->merge($unknown));
    assertType('Hypervel\Support\LazyCollection<int, mixed>', $lazy->mergeRecursive($unknown));
    assertType('Sentry\Integration\IntegrationInterface|null', $hub->getIntegration($name));
    assertType('mixed', $hub->withScope($unknown));
}
