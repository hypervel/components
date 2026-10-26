<?php

declare(strict_types=1);

namespace Hypervel\Head\Facades;

use Hypervel\Head\Schema\Article;
use Hypervel\Head\Schema\BlogPosting;
use Hypervel\Head\Schema\Brand;
use Hypervel\Head\Schema\Breadcrumbs;
use Hypervel\Head\Schema\Event;
use Hypervel\Head\Schema\Faq;
use Hypervel\Head\Schema\Offer;
use Hypervel\Head\Schema\Organization;
use Hypervel\Head\Schema\Person;
use Hypervel\Head\Schema\Product;
use Hypervel\Head\Schema\SchemaFactory;
use Hypervel\Head\Schema\WebPage;
use Hypervel\Head\Schema\WebSite;
use Hypervel\Support\Facades\Facade;

/**
 * @method static \Hypervel\Head\Schema\Article article()
 * @method static \Hypervel\Head\Schema\BlogPosting blogPosting()
 * @method static \Hypervel\Head\Schema\Brand brand()
 * @method static \Hypervel\Head\Schema\Breadcrumbs breadcrumbs()
 * @method static \Hypervel\Head\Schema\Event event()
 * @method static \Hypervel\Head\Schema\Faq faq()
 * @method static \Hypervel\Head\Schema\Offer offer()
 * @method static \Hypervel\Head\Schema\Organization organization()
 * @method static \Hypervel\Head\Schema\Person person()
 * @method static \Hypervel\Head\Schema\Place place()
 * @method static \Hypervel\Head\Schema\Product product()
 * @method static \Hypervel\Head\Schema\WebPage webPage()
 * @method static \Hypervel\Head\Schema\WebSite webSite()
 *
 * @see \Hypervel\Head\Schema\SchemaFactory
 *
 * @mixin SchemaFactory
 */
class Schema extends Facade
{
    /**
     * Get methods that should be excluded from the generated facade docblock.
     *
     * The SchemaFactory mixin preserves class-string and generic types
     * that generated method tags flatten.
     *
     * @return array<int, string>
     */
    protected static function ignoredFacadeDocumenterMethods(): array
    {
        return ['make', 'register'];
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'head.schema';
    }
}
