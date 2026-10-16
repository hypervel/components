<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Concerns;

use Hypervel\Mcp\Server\Contracts\Annotation as AnnotationContract;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;

trait HasAnnotations
{
    /**
     * Get the primitive annotations.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        $reflection = new ReflectionClass($this);

        /** @var Collection<int, AnnotationContract> $annotations */
        $annotations = collect($reflection->getAttributes())
            ->map(fn (ReflectionAttribute $attributeReflection): object => $attributeReflection->newInstance())
            ->filter(fn (object $attribute): bool => $attribute instanceof AnnotationContract)
            ->values();

        // @phpstan-ignore argument.templateType
        return $annotations
            ->each(function (AnnotationContract $attribute): void {
                $this->validateAnnotationUsage($attribute);
            })
            ->mapWithKeys(fn (AnnotationContract $attribute): array => [ // @phpstan-ignore argument.templateType
                $attribute->key() => $attribute->value, // @phpstan-ignore property.notFound
            ])
            ->all();
    }

    /**
     * Ensure the annotation is supported by this primitive.
     */
    private function validateAnnotationUsage(AnnotationContract $attribute): void
    {
        $allowedAnnotations = $this->allowedAnnotations();

        foreach ($allowedAnnotations as $allowedAnnotationClass) {
            if ($attribute instanceof $allowedAnnotationClass) {
                return;
            }
        }

        $allowedClasses = empty($allowedAnnotations)
            ? 'none'
            : implode(', ', $allowedAnnotations);

        throw new InvalidArgumentException(
            sprintf(
                'Annotation [%s] cannot be used on [%s]. Allowed annotation types: [%s]',
                $attribute::class,
                $this::class,
                $allowedClasses
            )
        );
    }

    /**
     * Get the annotation types allowed on this primitive.
     *
     * @return array<int, class-string>
     */
    protected function allowedAnnotations(): array
    {
        return [];
    }
}
