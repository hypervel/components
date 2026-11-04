<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Inertia\DevTools\EntryStore;
use Hypervel\Inertia\DevTools\RequestAttribute;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\ProvidesInertiaProperties;
use Hypervel\Inertia\RenderContext;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\Support\Header;
use RuntimeException;

class BroadcastOrderResource extends JsonResource
{
    public static ?string $wrap = null;

    /**
     * @var array{id: int, total: int}
     */
    private array $order;

    /**
     * Create a new broadcast order resource.
     *
     * @param array{id: int, total: int} $resource
     */
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);

        $this->order = $resource;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, total: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->order['id'],
            'total' => $this->order['total'],
        ];
    }
}

/**
 * @implements Arrayable<string, mixed>
 */
class BroadcastOrderSummary implements Arrayable
{
    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'id' => 1,
            'total' => 125,
        ];
    }
}

class BroadcastOrderUpdated
{
    /**
     * Get the data to broadcast.
     *
     * @return array{id: int, event: string, __inertia: array{props: array{status: string}}}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => 1,
            'event' => 'order.updated',
            ...Inertia::broadcastProps([
                'status' => 'paid',
            ]),
        ];
    }
}

class BroadcastOrderProperties implements ProvidesInertiaProperties
{
    /**
     * Get the Inertia properties.
     *
     * @return array{id: int, status: string}
     */
    public function toInertiaProperties(RenderContext $context): array
    {
        return [
            'id' => 1,
            'status' => 'paid',
        ];
    }
}

class BroadcastPropsTest extends TestCase
{
    public function testReturnsTheInertiaPropsEnvelopeAndNothingElse(): void
    {
        $payload = $this->broadcastProps([
            'order' => ['id' => 1],
        ]);

        $this->assertSame(['__inertia'], array_keys($payload));
        $this->assertSame(['props'], array_keys($payload['__inertia']));
        $this->assertSame(['order' => ['id' => 1]], $payload['__inertia']['props']);
    }

    public function testAJsonResourceValueResolvesToTheSameArrayAsAPageProp(): void
    {
        $order = ['id' => 1, 'total' => 125];

        $page = $this->renderPage([
            'order' => BroadcastOrderResource::make($order),
        ]);

        $payload = $this->broadcastProps([
            'order' => BroadcastOrderResource::make($order),
        ]);

        $this->assertSame($page['props']['order'], $payload['__inertia']['props']['order']);
    }

    public function testAClosureValueResolves(): void
    {
        $payload = $this->broadcastProps([
            'path' => fn (Request $request) => $request->path(),
        ], Request::create('/orders/1', 'GET'));

        $this->assertSame('orders/1', $payload['__inertia']['props']['path']);
    }

    public function testAnArrayableValueResolves(): void
    {
        $payload = $this->broadcastProps([
            'order' => new BroadcastOrderSummary,
        ]);

        $this->assertSame(['id' => 1, 'total' => 125], $payload['__inertia']['props']['order']);
    }

    public function testEveryPropNamedInThePayloadIsResolved(): void
    {
        $payload = $this->broadcastProps([
            'order' => ['id' => 1],
            'deferred' => Inertia::defer(fn () => 'deferred-value'),
            'optional' => Inertia::optional(fn () => 'optional-value'),
        ]);

        $this->assertSame([
            'order' => ['id' => 1],
            'deferred' => 'deferred-value',
            'optional' => 'optional-value',
        ], $payload['__inertia']['props']);
    }

    public function testAReusedScrollPropResolvesAgainOnEachBroadcast(): void
    {
        $callCount = 0;
        $feed = Inertia::scroll(function () use (&$callCount) {
            return ['data' => [['id' => ++$callCount]]];
        });

        $first = $this->broadcastProps(['feed' => $feed]);
        $second = $this->broadcastProps(['feed' => $feed]);

        $this->assertSame([['id' => 1]], $first['__inertia']['props']['feed']['data']);
        $this->assertSame([['id' => 2]], $second['__inertia']['props']['feed']['data']);
    }

    public function testARescuedPropIsLeftOutOfThePayload(): void
    {
        $payload = $this->broadcastProps([
            'order' => ['id' => 1],
            'stats' => Inertia::defer(function () {
                throw new RuntimeException('Rescue this broadcast prop');
            }, rescue: true),
        ]);

        $this->assertSame(['order' => ['id' => 1]], $payload['__inertia']['props']);
        $this->assertArrayNotHasKey('stats', $payload['__inertia']['props']);
    }

    public function testARescuedPropReturnedFromAClosureIsLeftOutOfThePayload(): void
    {
        $payload = $this->broadcastProps([
            'order' => ['id' => 1],
            'stats' => fn () => Inertia::defer(function () {
                throw new RuntimeException('Rescue this broadcast prop');
            }, rescue: true),
        ]);

        $this->assertSame(['order' => ['id' => 1]], $payload['__inertia']['props']);
    }

    public function testAOncePropTheRequestingClientAlreadyHasIsStillBroadcast(): void
    {
        $request = Request::create('/orders/1', 'GET');
        $request->headers->add([
            Header::INERTIA => 'true',
            Header::EXCEPT_ONCE_PROPS => 'settings',
        ]);

        $payload = $this->broadcastProps([
            'settings' => Inertia::once(fn () => ['theme' => 'dark']),
        ], $request);

        $this->assertSame(['theme' => 'dark'], $payload['__inertia']['props']['settings']);
    }

    public function testPartialReloadHeadersDoNotFilterThePayload(): void
    {
        $request = Request::create('/orders/1', 'GET');
        $request->headers->add([
            Header::INERTIA => 'true',
            Header::PARTIAL_COMPONENT => 'Orders/Show',
            Header::PARTIAL_EXCEPT => 'order',
        ]);

        $payload = $this->broadcastProps([
            'order' => ['id' => 1],
        ], $request);

        $this->assertSame(['id' => 1], $payload['__inertia']['props']['order']);
    }

    public function testBroadcastingDuringAPageRenderDoesNotRecordThePropsOnTheDevtoolsEntry(): void
    {
        config()->set('inertia.devtools.enabled', true);
        config()->set('inertia.devtools.except', []);

        $request = Request::create('/orders/1', 'GET');
        $request->headers->add([Header::INERTIA => 'true']);
        $this->bindRequest($request);

        $response = Inertia::render('Orders/Show', ['order' => ['id' => 1]]);

        Inertia::broadcastProps(['broadcastOnlyStatus' => 'paid']);

        $response->toResponse($request);

        $payload = $request->attributes->get(RequestAttribute::PAYLOAD);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('order', $payload['props']);
        $this->assertArrayNotHasKey('broadcastOnlyStatus', $payload['props']);
    }

    public function testAnAlwaysBroadcastPropStillResolves(): void
    {
        $payload = $this->broadcastProps([
            'always' => Inertia::always('always-value'),
        ]);

        $this->assertSame('always-value', $payload['__inertia']['props']['always']);
    }

    public function testDotPathKeysStayFlatAndLiteral(): void
    {
        $payload = $this->broadcastProps([
            'order.total' => 125,
        ]);

        $this->assertSame(['order.total' => 125], $payload['__inertia']['props']);
        $this->assertArrayNotHasKey('order', $payload['__inertia']['props']);
    }

    public function testTheAuthorsOwnSiblingKeysInBroadcastWithAreUntouched(): void
    {
        $this->bindRequest(Request::create('/orders/1', 'GET'));

        $payload = (new BroadcastOrderUpdated)->broadcastWith();

        $this->assertSame(['id', 'event', '__inertia'], array_keys($payload));
        $this->assertSame(1, $payload['id']);
        $this->assertSame('order.updated', $payload['event']);
        $this->assertSame(['status' => 'paid'], $payload['__inertia']['props']);
    }

    public function testMultiplePropsResolveInOneCall(): void
    {
        $payload = $this->broadcastProps([
            'order' => ['id' => 1],
            'status' => 'paid',
        ]);

        $this->assertSame([
            'order' => ['id' => 1],
            'status' => 'paid',
        ], $payload['__inertia']['props']);
    }

    public function testANumericallyKeyedPropertyProviderContributesItsOwnKeys(): void
    {
        $payload = $this->broadcastProps([
            new BroadcastOrderProperties,
        ]);

        $this->assertSame(['id' => 1, 'status' => 'paid'], $payload['__inertia']['props']);
    }

    public function testConstructingTheBroadcastResolverDoesNotRecordADevtoolsEntry(): void
    {
        config()->set('inertia.devtools.enabled', true);
        config()->set('inertia.devtools.except', []);

        $request = Request::create('/orders/1', 'GET');

        $this->broadcastProps([
            'order' => fn () => ['id' => 1],
        ], $request);

        $this->assertNull($this->app->make(EntryStore::class)->current());
        $this->assertFalse($request->attributes->has(RequestAttribute::PAYLOAD));
    }

    /**
     * Resolve the given broadcast props for the given request.
     *
     * @param array<array-key, mixed> $props
     * @return array{__inertia: array{props: array<array-key, mixed>}}
     */
    protected function broadcastProps(array $props, ?Request $request = null): array
    {
        $this->bindRequest($request ?? Request::create('/broadcast', 'GET'));

        return Inertia::broadcastProps($props);
    }

    /**
     * Make the given request the current request.
     */
    protected function bindRequest(Request $request): void
    {
        RequestContext::set($request);
    }

    /**
     * Render the given props as an Inertia page.
     *
     * @param array<array-key, mixed> $props
     * @return array<string, mixed>
     */
    protected function renderPage(array $props): array
    {
        $request = Request::create('/orders/1', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);

        $response = (new Response('Orders/Show', [], $props, 'app', '123'))->toResponse($request);

        $this->assertInstanceOf(JsonResponse::class, $response);

        return $response->getData(true);
    }
}
