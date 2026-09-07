<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items {
    class ParentClassItem
    {
    }

    class ChildClassItem
    {
    }

    class InlineItem
    {
    }

    class ConstructorItem
    {
    }
}

namespace Hypervel\Tests\Data\Fixtures\DataClassAnnotations\ParentScope {
    use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\InlineItem as ScopedInlineItem;
    use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ParentClassItem as ScopedClassItem;

    /**
     * @property array<ScopedClassItem> $parentOnly
     * @property array<ScopedClassItem> $classItems
     * @property array<ScopedClassItem> $inlineItems
     * @property array<ScopedClassItem> $constructorItems
     */
    class ParentAnnotations
    {
        public array $parentOnly;

        public array $classItems;

        /** @var array<ScopedInlineItem> */
        public array $inlineItems;

        /** @var array<ScopedInlineItem> */
        public array $constructorItems;
    }
}

namespace Hypervel\Tests\Data\Fixtures\DataClassAnnotations\ChildScope {
    use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ChildClassItem as ScopedClassItem;
    use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ConstructorItem as ScopedConstructorItem;
    use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\ParentScope\ParentAnnotations;

    /**
     * @property array<ScopedClassItem> $classItems
     * @property array<ScopedClassItem> $inlineItems
     * @property array<ScopedClassItem> $constructorItems
     */
    class ChildAnnotations extends ParentAnnotations
    {
        /**
         * Create a new child annotation fixture.
         *
         * @param array<ScopedConstructorItem> $constructorItems
         */
        public function __construct(array $constructorItems)
        {
            $this->constructorItems = $constructorItems;
        }
    }
}
