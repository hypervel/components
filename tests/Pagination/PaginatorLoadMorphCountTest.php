<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Support\HtmlString;
use Hypervel\Tests\TestCase;
use Mockery as m;

class PaginatorLoadMorphCountTest extends TestCase
{
    public function testCollectionLoadMorphCountCanChainOnThePaginator(): void
    {
        $relations = [
            'App\User' => 'photos',
            'App\Company' => ['employees', 'calendars'],
        ];

        $items = m::mock(Collection::class);
        $items->expects('loadMorphCount')->with('parentable', $relations);

        $p = (new class extends AbstractPaginator {
            /**
             * Render an empty pagination view.
             */
            public function render(?string $view = null, array $data = []): Htmlable
            {
                return new HtmlString('');
            }

            /**
             * Determine whether more pages are available.
             */
            public function hasMorePages(): bool
            {
                return false;
            }
        })->setCollection($items);

        $this->assertSame($p, $p->loadMorphCount('parentable', $relations));
    }
}
