<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Concerns;

use Hypervel\Mcp\Server\Contracts\Annotation as AnnotationContract;
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

        $annotations = [];

        foreach ($reflection->getAttributes(AnnotationContract::class, ReflectionAttribute::IS_INSTANCEOF) as $attributeReflection) {
            $attribute = $attributeReflection->newInstance();

            $this->validateAnnotationUsage($attribute);

            $annotations[$attribute->key()] = $attribute->value; // @phpstan-ignore property.notFound
        }

        return $annotations;
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
