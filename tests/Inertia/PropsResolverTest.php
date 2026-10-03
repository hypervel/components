<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use ArrayObject;
use DateTimeImmutable;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response as BaseResponse;
use Hypervel\Inertia\DevTools\RequestRecorder;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\MergeProp;
use Hypervel\Inertia\PropertyContext;
use Hypervel\Inertia\ProvidesInertiaProperties;
use Hypervel\Inertia\ProvidesInertiaProperty;
use Hypervel\Inertia\ProvidesScrollMetadata;
use Hypervel\Inertia\RenderContext;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\ScrollProp;
use Hypervel\Tests\Inertia\Fixtures\Enums\UnitEnum;
use Hypervel\View\ViewException;
use JsonSerializable;
use RuntimeException;
use stdClass;

class PropsResolverTest extends TestCase
{
    public function testNestedClosureIsResolved(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => fn () => ['user' => 'Jonathan'],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
    }

    public function testNestedClosureInsideArrayIsResolved(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => [
                'user' => fn () => 'Jonathan',
            ],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
    }

    public function testNestedProvidesInertiaPropertiesIsResolved(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => [
                new class implements ProvidesInertiaProperties {
                    public function toInertiaProperties(RenderContext $context): iterable
                    {
                        return ['user' => 'Jonathan', 'role' => 'admin'];
                    }
                },
                'team' => 'Inertia',
            ],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
        $this->assertSame('admin', $page['props']['auth']['role']);
        $this->assertSame('Inertia', $page['props']['auth']['team']);
    }

    public function testNestedProvidesInertiaPropertiesWithPropTypes(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => [
                new class implements ProvidesInertiaProperties {
                    public function toInertiaProperties(RenderContext $context): iterable
                    {
                        return [
                            'user' => 'Jonathan',
                            'permissions' => Inertia::optional(fn () => ['manage-users']),
                        ];
                    }
                },
            ],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
        $this->assertArrayNotHasKey('permissions', $page['props']['auth']);
    }

    public function testNestedProvidesInertiaPropertiesWithPropTypesOnPartialRequest(): void
    {
        $page = $this->makePage(
            $this->makePartialRequest('auth.permissions'),
            [
                'auth' => [
                    new class implements ProvidesInertiaProperties {
                        public function toInertiaProperties(RenderContext $context): iterable
                        {
                            return [
                                'user' => 'Jonathan',
                                'permissions' => Inertia::optional(fn () => ['manage-users']),
                            ];
                        }
                    },
                ],
            ],
        );

        $this->assertArrayNotHasKey('user', $page['props']['auth']);
        $this->assertSame(['manage-users'], $page['props']['auth']['permissions']);
    }

    public function testNestedAlwaysPropIsResolved(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => [
                'user' => Inertia::always(fn () => 'Jonathan'),
            ],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
    }

    public function testCallableLookingGenericPropValuesRemainData(): void
    {
        $target = new class {
            public function resolve(): string
            {
                return 'resolved';
            }
        };
        $callableArray = [$target, 'resolve'];

        $page = $this->makePage(Request::create('/'), [
            'callableArray' => $callableArray,
            'functionName' => 'date',
        ]);

        $this->assertSame($callableArray, $page['props']['callableArray']);
        $this->assertSame('date', $page['props']['functionName']);
    }

    public function testNestedMergePropIsResolved(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'feed' => [
                'posts' => new MergeProp([['id' => 1]]),
            ],
        ]);

        $this->assertSame([['id' => 1]], $page['props']['feed']['posts']);
    }

    public function testNestedOncePropIsResolvedOnInitialLoad(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'config' => [
                'locale' => Inertia::once(fn () => 'en'),
            ],
        ]);

        $this->assertSame('en', $page['props']['config']['locale']);
    }

    public function testNestedOptionalPropIsExcludedFromInitialLoad(): void
    {
        $resolved = false;

        $page = $this->makePage(Request::create('/'), [
            'auth' => [
                'user' => 'Jonathan',
                'permissions' => Inertia::optional(function () use (&$resolved) {
                    $resolved = true;

                    return ['admin'];
                }),
            ],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
        $this->assertArrayNotHasKey('permissions', $page['props']['auth']);
        $this->assertFalse($resolved, 'OptionalProp closure should not be resolved on initial load');
    }

    public function testNestedDeferPropIsExcludedFromInitialLoad(): void
    {
        $resolved = false;

        $page = $this->makePage(Request::create('/'), [
            'auth' => [
                'user' => 'Jonathan',
                'notifications' => Inertia::defer(function () use (&$resolved) {
                    $resolved = true;

                    return [];
                }),
            ],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
        $this->assertArrayNotHasKey('notifications', $page['props']['auth']);
        $this->assertFalse($resolved, 'DeferProp closure should not be resolved on initial load');
    }

    public function testRescuedDeferPropIsOmittedAndMetadataOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('auth.notifications'), [
            'auth' => [
                'notifications' => Inertia::defer(function () {
                    throw new RuntimeException('Rescue this deferred prop');
                }, rescue: true),
            ],
        ]);

        $this->assertArrayNotHasKey('notifications', $page['props']['auth']);
        $this->assertSame(['auth.notifications'], $page['rescuedProps']);
    }

    public function testRescuedDeferPropIsOmittedWhenProvidesInertiaPropertyThrows(): void
    {
        $page = $this->makePage($this->makePartialRequest('stats'), [
            'stats' => Inertia::defer(function () {
                return new class implements ProvidesInertiaProperty {
                    public function toInertiaProperty(PropertyContext $prop): mixed
                    {
                        throw new RuntimeException('Failed to resolve stats');
                    }
                };
            }, rescue: true),
        ]);

        $this->assertArrayNotHasKey('stats', $page['props']);
        $this->assertSame(['stats'], $page['rescuedProps']);
    }

    public function testExcludedPropsAreNotResolvedOnInitialLoad(): void
    {
        $optionalResolved = false;
        $deferResolved = false;

        $page = $this->makePage(Request::create('/'), [
            'name' => 'Jonathan',
            'permissions' => Inertia::optional(function () use (&$optionalResolved) {
                $optionalResolved = true;

                return ['admin'];
            }),
            'notifications' => Inertia::defer(function () use (&$deferResolved) {
                $deferResolved = true;

                return ['You have a new follower'];
            }),
        ]);

        $this->assertSame('Jonathan', $page['props']['name']);
        $this->assertArrayNotHasKey('permissions', $page['props']);
        $this->assertArrayNotHasKey('notifications', $page['props']);
        $this->assertFalse($optionalResolved, 'OptionalProp closure should not be resolved on initial load');
        $this->assertFalse($deferResolved, 'DeferProp closure should not be resolved on initial load');
    }

    public function testClosureReturningOptionalPropIsExcludedFromInitialLoad(): void
    {
        $resolved = false;

        $page = $this->makePage(Request::create('/'), [
            'auth' => fn () => [
                'user' => 'Jonathan',
                'permissions' => Inertia::optional(function () use (&$resolved) {
                    $resolved = true;

                    return ['admin'];
                }),
            ],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
        $this->assertArrayNotHasKey('permissions', $page['props']['auth']);
        $this->assertFalse($resolved, 'OptionalProp closure should not be resolved on initial load'); // @phpstan-ignore method.impossibleType
    }

    public function testClosureReturningDeferPropIsExcludedFromInitialLoad(): void
    {
        $resolved = false;

        $page = $this->makePage(Request::create('/'), [
            'auth' => fn () => [
                'user' => 'Jonathan',
                'notifications' => Inertia::defer(function () use (&$resolved) {
                    $resolved = true;

                    return [];
                }),
            ],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
        $this->assertArrayNotHasKey('notifications', $page['props']['auth']);
        $this->assertFalse($resolved, 'DeferProp closure should not be resolved on initial load'); // @phpstan-ignore method.impossibleType
    }

    public function testClosureReturningMergePropResolvesWithMetadata(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'posts' => fn () => new MergeProp([['id' => 1]]),
        ]);

        $this->assertSame([['id' => 1]], $page['props']['posts']);
        $this->assertSame(['posts'], $page['mergeProps']);
    }

    public function testClosureReturningOncePropResolvesWithMetadata(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'locale' => fn () => Inertia::once(fn () => 'en'),
        ]);

        $this->assertSame('en', $page['props']['locale']);
        $this->assertSame(['locale' => ['prop' => 'locale', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testClosureReturningDeferPropCollectsDeferredAndMergeMetadata(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'posts' => fn () => Inertia::defer(fn () => [['id' => 1]])->merge(),
        ]);

        $this->assertArrayNotHasKey('posts', $page['props']);
        $this->assertSame(['default' => ['posts']], $page['deferredProps']);
        $this->assertSame(['posts'], $page['mergeProps']);
    }

    public function testNestedOptionalPropIsIncludedOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('auth.permissions'), [
            'auth' => [
                'user' => 'Jonathan',
                'permissions' => Inertia::optional(fn () => ['admin']),
            ],
        ]);

        $this->assertSame(['admin'], $page['props']['auth']['permissions']);
    }

    public function testNestedDeferPropIsIncludedOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('auth.notifications'), [
            'auth' => [
                'user' => 'Jonathan',
                'notifications' => Inertia::defer(fn () => ['new message']),
            ],
        ]);

        $this->assertSame(['new message'], $page['props']['auth']['notifications']);
    }

    public function testNestedAlwaysPropIsIncludedOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('auth.user'), [
            'auth' => [
                'user' => 'Jonathan',
                'errors' => Inertia::always(fn () => ['name' => 'required']),
            ],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
        $this->assertSame(['name' => 'required'], $page['props']['auth']['errors']);
    }

    public function testNestedAlwaysPropIsIncludedWhenItsAncestorsAreNotRequested(): void
    {
        $profileResolutions = 0;

        $page = $this->makePage($this->makePartialRequest('other'), [
            'other' => 'included',
            'auth' => [
                'user' => [
                    'profile' => function () use (&$profileResolutions): array {
                        ++$profileResolutions;

                        return ['name' => 'Jonathan'];
                    },
                    'errors' => Inertia::always(fn () => ['name' => 'required']),
                ],
            ],
        ]);

        $this->assertSame('included', $page['props']['other']);
        $this->assertSame(['name' => 'required'], $page['props']['auth']['user']['errors']);
        $this->assertArrayNotHasKey('profile', $page['props']['auth']['user']);
        $this->assertSame(0, $profileResolutions);
    }

    public function testNestedAlwaysPropIsIncludedWhenItsAncestorIsExcepted(): void
    {
        $request = $this->makePartialRequest('other');
        $request->headers->set('X-Inertia-Partial-Except', 'auth');

        $page = $this->makePage($request, [
            'other' => 'included',
            'auth' => [
                'user' => 'Jonathan',
                'errors' => Inertia::always(fn () => ['name' => 'required']),
            ],
        ]);

        $this->assertSame('included', $page['props']['other']);
        $this->assertSame(['name' => 'required'], $page['props']['auth']['errors']);
        $this->assertArrayNotHasKey('user', $page['props']['auth']);
    }

    public function testTopLevelAlwaysPropIsIncludedWhenNotRequested(): void
    {
        $page = $this->makePage($this->makePartialRequest('other'), [
            'other' => 'value',
            'errors' => Inertia::always(fn () => ['name' => 'required']),
        ]);

        $this->assertSame('value', $page['props']['other']);
        $this->assertSame(['name' => 'required'], $page['props']['errors']);
    }

    public function testNestedMergePropIsIncludedOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('feed.posts'), [
            'feed' => [
                'posts' => new MergeProp([['id' => 1]]),
            ],
        ]);

        $this->assertSame([['id' => 1]], $page['props']['feed']['posts']);
    }

    public function testNestedPropIsExcludedViaExceptHeader(): void
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'TestComponent']);
        $request->headers->add(['X-Inertia-Partial-Data' => 'auth']);
        $request->headers->add(['X-Inertia-Partial-Except' => 'auth.token']);

        $page = $this->makePage($request, [
            'auth' => [
                'user' => 'Jonathan',
                'token' => 'secret',
            ],
        ]);

        $this->assertSame('Jonathan', $page['props']['auth']['user']);
        $this->assertArrayNotHasKey('token', $page['props']['auth']);
    }

    public function testPartialRequestForParentResolvesAllNestedPropTypes(): void
    {
        $page = $this->makePage($this->makePartialRequest('dashboard'), [
            'dashboard' => [
                'stats' => 'visible',
                'feed' => new MergeProp([['id' => 1]]),
                'notifications' => Inertia::defer(fn () => ['msg']),
                'settings' => Inertia::optional(fn () => ['theme' => 'dark']),
                'locale' => Inertia::once(fn () => 'en'),
            ],
        ]);

        $this->assertSame('visible', $page['props']['dashboard']['stats']);
        $this->assertSame([['id' => 1]], $page['props']['dashboard']['feed']);
        $this->assertSame(['msg'], $page['props']['dashboard']['notifications']);
        $this->assertSame(['theme' => 'dark'], $page['props']['dashboard']['settings']);
        $this->assertSame('en', $page['props']['dashboard']['locale']);
        $this->assertSame(['dashboard.feed'], $page['mergeProps']);
        $this->assertSame(['dashboard.locale' => ['prop' => 'dashboard.locale', 'expiresAt' => null]], $page['onceProps']);
        $this->assertArrayNotHasKey('deferredProps', $page);
    }

    public function testNestedDeferPropMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => [
                'user' => 'Jonathan',
                'notifications' => Inertia::defer(fn () => []),
            ],
        ]);

        $this->assertSame(['default' => ['auth.notifications']], $page['deferredProps']);
    }

    public function testNestedDeferPropMetadataPreservesGroup(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => [
                'notifications' => Inertia::defer(fn () => [], 'sidebar'),
                'messages' => Inertia::defer(fn () => [], 'sidebar'),
            ],
        ]);

        $this->assertSame(['sidebar' => ['auth.notifications', 'auth.messages']], $page['deferredProps']);
    }

    public function testClosureReturningDeferPropMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => fn () => [
                'user' => 'Jonathan',
                'notifications' => Inertia::defer(fn () => [], 'alerts'),
            ],
        ]);

        $this->assertSame(['alerts' => ['auth.notifications']], $page['deferredProps']);
    }

    public function testNestedMergePropMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'feed' => [
                'posts' => new MergeProp([['id' => 1]]),
            ],
        ]);

        $this->assertSame(['feed.posts'], $page['mergeProps']);
    }

    public function testNestedPrependMergePropMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'feed' => [
                'posts' => (new MergeProp([['id' => 1]]))->prepend(),
            ],
        ]);

        $this->assertSame(['feed.posts'], $page['prependProps']);
    }

    public function testNestedDeepMergePropMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'settings' => [
                'preferences' => (new MergeProp(['theme' => 'dark']))->deepMerge(),
            ],
        ]);

        $this->assertSame(['settings.preferences'], $page['deepMergeProps']);
    }

    public function testNestedMergePropWithNestedPathMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'feed' => [
                'posts' => (new MergeProp(['data' => [['id' => 1]]]))->append('data'),
            ],
        ]);

        $this->assertSame(['feed.posts.data'], $page['mergeProps']);
    }

    public function testNestedMergePropWithMatchOnMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'feed' => [
                'posts' => (new MergeProp([['id' => 1]]))->matchOn('id')->deepMerge(),
            ],
        ]);

        $this->assertSame(['feed.posts'], $page['deepMergeProps']);
        $this->assertSame(['feed.posts.id'], $page['matchPropsOn']);
    }

    public function testNestedDeferWithMergeMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'feed' => [
                'posts' => Inertia::defer(fn () => [['id' => 1]])->merge(),
            ],
        ]);

        $this->assertSame(['default' => ['feed.posts']], $page['deferredProps']);
        $this->assertSame(['feed.posts'], $page['mergeProps']);
    }

    public function testNestedMergeMetadataIsCollectedOnExactPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('feed.posts'), [
            'feed' => [
                'posts' => new MergeProp([['id' => 1]]),
            ],
        ]);

        $this->assertSame([['id' => 1]], $page['props']['feed']['posts']);
        $this->assertSame(['feed.posts'], $page['mergeProps']);
    }

    public function testNestedMergeMetadataIsCollectedWhenParentIsRequested(): void
    {
        $page = $this->makePage($this->makePartialRequest('feed'), [
            'feed' => [
                'posts' => new MergeProp([['id' => 1]]),
            ],
        ]);

        $this->assertSame([['id' => 1]], $page['props']['feed']['posts']);
        $this->assertSame(['feed.posts'], $page['mergeProps']);
    }

    public function testNestedMergePropMetadataIsSuppressedByResetHeader(): void
    {
        $request = $this->makePartialRequest('feed.posts');
        $request->headers->add(['X-Inertia-Reset' => 'feed.posts']);

        $page = $this->makePage($request, [
            'feed' => [
                'posts' => new MergeProp([['id' => 1]]),
            ],
        ]);

        $this->assertSame([['id' => 1]], $page['props']['feed']['posts']);
        $this->assertArrayNotHasKey('mergeProps', $page);
    }

    public function testPartialAndResetHeadersPreserveAZeroPropPath(): void
    {
        $request = $this->makePartialRequest('0');
        $request->headers->add(['X-Inertia-Reset' => '0']);

        $page = $this->makePage($request, [
            '0' => new MergeProp([['id' => 1]]),
            'other' => 'value',
        ]);

        $this->assertSame([['id' => 1]], $page['props'][0]);
        $this->assertArrayNotHasKey('other', $page['props']);
        $this->assertArrayNotHasKey('mergeProps', $page);
    }

    public function testResetHeaderDoesNotCoerceNumericPropPaths(): void
    {
        $request = $this->makePartialRequest('0');
        $request->headers->add(['X-Inertia-Reset' => '0.0']);

        $page = $this->makePage($request, [
            '0' => new ScrollProp(
                ['data' => [['id' => 1]]],
                'data',
                $this->makeScrollMetadata(),
            ),
        ]);

        $this->assertSame(['0.data'], $page['mergeProps']);
        $this->assertFalse($page['scrollProps'][0]['reset']);
    }

    public function testNestedOncePropMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'config' => [
                'locale' => Inertia::once(fn () => 'en'),
            ],
        ]);

        $this->assertSame(['config.locale' => ['prop' => 'config.locale', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testNestedOncePropWithCustomKeyMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'config' => [
                'locale' => Inertia::once(fn () => 'en')->as('app-locale'),
            ],
        ]);

        $this->assertSame(['app-locale' => ['prop' => 'config.locale', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testNestedOncePropIsExcludedWhenAlreadyLoaded(): void
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Except-Once-Props' => 'config.locale']);

        $page = $this->makePage($request, [
            'config' => [
                'locale' => Inertia::once(fn () => 'en'),
                'timezone' => 'UTC',
            ],
        ]);

        $this->assertSame('UTC', $page['props']['config']['timezone']);
        $this->assertArrayNotHasKey('locale', $page['props']['config']);
        $this->assertSame(['config.locale' => ['prop' => 'config.locale', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testOnceHeaderPreservesAZeroCustomKey(): void
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Except-Once-Props' => '0']);

        $page = $this->makePage($request, [
            'locale' => Inertia::once(fn () => 'en')->as('0'),
        ]);

        $this->assertArrayNotHasKey('locale', $page['props']);
        $this->assertSame(['0' => ['prop' => 'locale', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testOnceHeaderDoesNotCoerceNumericCustomKeys(): void
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Except-Once-Props' => '0.0']);

        $page = $this->makePage($request, [
            'locale' => Inertia::once(fn () => 'en')->as('0'),
        ]);

        $this->assertSame('en', $page['props']['locale']);
        $this->assertSame(['0' => ['prop' => 'locale', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testNestedOnceMetadataIsCollectedOnExactPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('config.locale'), [
            'config' => [
                'locale' => Inertia::once(fn () => 'en'),
            ],
        ]);

        $this->assertSame('en', $page['props']['config']['locale']);
        $this->assertSame(['config.locale' => ['prop' => 'config.locale', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testNestedOnceMetadataIsCollectedWhenParentIsRequested(): void
    {
        $page = $this->makePage($this->makePartialRequest('config'), [
            'config' => [
                'locale' => Inertia::once(fn () => 'en'),
            ],
        ]);

        $this->assertSame('en', $page['props']['config']['locale']);
        $this->assertSame(['config.locale' => ['prop' => 'config.locale', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testNestedScrollPropMetadataIsCollected(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'feed' => [
                'posts' => new ScrollProp(
                    ['data' => [['id' => 1]]],
                    'data',
                    $this->makeScrollMetadata(),
                ),
            ],
        ]);

        $this->assertSame([
            'feed.posts' => [
                'pageName' => 'page',
                'previousPage' => null,
                'nextPage' => 2,
                'currentPage' => 1,
                'reset' => false,
            ],
        ], $page['scrollProps']);
    }

    public function testSharedScrollPropResolvesIndependentlyAtEachPath(): void
    {
        $callCount = 0;
        $scrollProp = new ScrollProp(
            function () use (&$callCount) {
                return ['data' => [['id' => ++$callCount]]];
            },
            'data',
            fn () => $this->makeScrollMetadata(),
        );

        $page = $this->makePage(Request::create('/'), [
            'first' => $scrollProp,
            'second' => $scrollProp,
        ]);

        $this->assertSame(2, $callCount);
        $this->assertSame([['id' => 1]], $page['props']['first']['data']);
        $this->assertSame([['id' => 2]], $page['props']['second']['data']);
        $this->assertSame(['first.data', 'second.data'], $page['mergeProps']);
        $this->assertSame(['first', 'second'], array_keys($page['scrollProps']));
    }

    public function testPropertyProviderScrollPropResolvesWithoutMutatingItsSource(): void
    {
        $callCount = 0;
        $scrollProp = new ScrollProp(
            function () use (&$callCount) {
                ++$callCount;

                return ['data' => [['id' => 1]]];
            },
            'data',
            fn () => $this->makeScrollMetadata(),
        );

        $provider = new class($scrollProp) implements ProvidesInertiaProperties {
            public function __construct(private readonly ScrollProp $scrollProp)
            {
            }

            public function toInertiaProperties(RenderContext $context): iterable
            {
                return ['feed' => $this->scrollProp];
            }
        };

        $page = $this->makePage(Request::create('/'), [$provider]);

        $this->assertSame(1, $callCount);
        $this->assertSame([['id' => 1]], $page['props']['feed']['data']);
        $this->assertSame(['feed.data'], $page['mergeProps']);
        $this->assertSame([], $scrollProp->appendsAtPaths());
    }

    public function testNestedDeferredScrollPropIsExcludedFromInitialLoad(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'feed' => [
                'posts' => (new ScrollProp(
                    ['data' => [['id' => 1]]],
                    'data',
                    $this->makeScrollMetadata(),
                ))->defer(),
            ],
        ]);

        $this->assertArrayNotHasKey('posts', $page['props']['feed'] ?? []);
        $this->assertSame(['default' => ['feed.posts']], $page['deferredProps']);
    }

    public function testNestedScrollPropIsIncludedOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('feed.posts'), [
            'feed' => [
                'posts' => new ScrollProp(
                    ['data' => [['id' => 1]]],
                    'data',
                    $this->makeScrollMetadata(),
                ),
            ],
        ]);

        $this->assertSame(['data' => [['id' => 1]]], $page['props']['feed']['posts']);
        $this->assertSame([
            'feed.posts' => [
                'pageName' => 'page',
                'previousPage' => null,
                'nextPage' => 2,
                'currentPage' => 1,
                'reset' => false,
            ],
        ], $page['scrollProps']);
    }

    public function testNestedScrollPropResetFlagIsSetByResetHeader(): void
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia-Reset' => 'feed.posts']);

        $page = $this->makePage($request, [
            'feed' => [
                'posts' => new ScrollProp(
                    ['data' => [['id' => 1]]],
                    'data',
                    $this->makeScrollMetadata(),
                ),
            ],
        ]);

        $this->assertTrue($page['scrollProps']['feed.posts']['reset']);
    }

    public function testNestedDeferOncePropSuppressesDeferredMetadataWhenAlreadyLoaded(): void
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia-Except-Once-Props' => 'feed.posts']);

        $page = $this->makePage($request, [
            'feed' => [
                'posts' => Inertia::defer(fn () => [])->once(),
            ],
        ]);

        $this->assertArrayNotHasKey('deferredProps', $page);
    }

    public function testNestedDeferOncePropIncludesDeferredMetadataOnFirstLoad(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'feed' => [
                'posts' => Inertia::defer(fn () => [])->once(),
            ],
        ]);

        $this->assertSame(['default' => ['feed.posts']], $page['deferredProps']);
        $this->assertSame(['feed.posts' => ['prop' => 'feed.posts', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testNestedPropsOnNonPartialInertiaRequestBehaveLikeInitialLoad(): void
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia' => 'true']);

        $page = $this->makePage($request, [
            'dashboard' => [
                'stats' => 'visible',
                'feed' => new MergeProp([['id' => 1]]),
                'notifications' => Inertia::defer(fn () => []),
                'settings' => Inertia::optional(fn () => []),
            ],
        ]);

        $this->assertSame('visible', $page['props']['dashboard']['stats']);
        $this->assertSame([['id' => 1]], $page['props']['dashboard']['feed']);
        $this->assertArrayNotHasKey('notifications', $page['props']['dashboard']);
        $this->assertArrayNotHasKey('settings', $page['props']['dashboard']);
        $this->assertSame(['dashboard.feed'], $page['mergeProps']);
        $this->assertSame(['default' => ['dashboard.notifications']], $page['deferredProps']);
    }

    public function testExceptHeaderSuppressesNestedMergeMetadata(): void
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'TestComponent']);
        $request->headers->add(['X-Inertia-Partial-Data' => 'feed.posts,feed.comments']);
        $request->headers->add(['X-Inertia-Partial-Except' => 'feed.posts']);

        $page = $this->makePage($request, [
            'feed' => [
                'posts' => new MergeProp([['id' => 1]]),
                'comments' => new MergeProp([['id' => 2]]),
            ],
        ]);

        $this->assertArrayNotHasKey('posts', $page['props']['feed']);
        $this->assertSame([['id' => 2]], $page['props']['feed']['comments']);
        $this->assertSame(['feed.comments'], $page['mergeProps']);
    }

    public function testExceptHeaderForParentSuppressesAllNestedMetadata(): void
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'TestComponent']);
        $request->headers->add(['X-Inertia-Partial-Data' => 'feed,other']);
        $request->headers->add(['X-Inertia-Partial-Except' => 'feed']);

        $page = $this->makePage($request, [
            'feed' => [
                'posts' => new MergeProp([['id' => 1]]),
            ],
            'other' => 'value',
        ]);

        $this->assertArrayNotHasKey('feed', $page['props']);
        $this->assertSame('value', $page['props']['other']);
        $this->assertArrayNotHasKey('mergeProps', $page);
    }

    public function testDeeplyNestedDeferPropIsExcludedWithMetadata(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'app' => [
                'auth' => [
                    'notifications' => Inertia::defer(fn () => [], 'alerts'),
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('notifications', $page['props']['app']['auth']);
        $this->assertSame(['alerts' => ['app.auth.notifications']], $page['deferredProps']);
    }

    public function testDeeplyNestedMergePropMetadataUsesFullPath(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'app' => [
                'feed' => [
                    'posts' => new MergeProp([['id' => 1]]),
                ],
            ],
        ]);

        $this->assertSame(['app.feed.posts'], $page['mergeProps']);
    }

    public function testDeeplyNestedOptionalPropIsIncludedOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('app.auth.permissions'), [
            'app' => [
                'auth' => [
                    'permissions' => Inertia::optional(fn () => ['admin']),
                ],
            ],
        ]);

        $this->assertSame(['admin'], $page['props']['app']['auth']['permissions']);
    }

    public function testMultipleNestedPropTypesAreHandledTogether(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'dashboard' => [
                'stats' => 'visible',
                'feed' => new MergeProp([['id' => 1]]),
                'notifications' => Inertia::defer(fn () => []),
                'settings' => Inertia::optional(fn () => []),
                'locale' => Inertia::once(fn () => 'en'),
            ],
        ]);

        $this->assertSame('visible', $page['props']['dashboard']['stats']);
        $this->assertSame([['id' => 1]], $page['props']['dashboard']['feed']);
        $this->assertSame('en', $page['props']['dashboard']['locale']);
        $this->assertArrayNotHasKey('notifications', $page['props']['dashboard']);
        $this->assertArrayNotHasKey('settings', $page['props']['dashboard']);
        $this->assertSame(['dashboard.feed'], $page['mergeProps']);
        $this->assertSame(['default' => ['dashboard.notifications']], $page['deferredProps']);
        $this->assertSame(['dashboard.locale' => ['prop' => 'dashboard.locale', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testDeferredPropsAtMixedDepthsCollectCorrectMetadata(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'foo' => Inertia::defer(fn () => 'bar'),
            'nested' => [
                'a' => 'b',
                'c' => Inertia::defer(fn () => 'd'),
            ],
        ]);

        $this->assertSame('b', $page['props']['nested']['a']);
        $this->assertArrayNotHasKey('foo', $page['props']);
        $this->assertArrayNotHasKey('c', $page['props']['nested']);
        $this->assertSame(['default' => ['foo', 'nested.c']], $page['deferredProps']);
    }

    public function testDeferredPropsAtMixedDepthsResolveOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('foo,nested.c'), [
            'foo' => Inertia::defer(fn () => 'bar'),
            'nested' => [
                'a' => 'b',
                'c' => Inertia::defer(fn () => 'd'),
            ],
        ]);

        $this->assertSame('bar', $page['props']['foo']);
        $this->assertSame('d', $page['props']['nested']['c']);
        $this->assertArrayNotHasKey('a', $page['props']['nested']);
        $this->assertArrayNotHasKey('deferredProps', $page);
    }

    public function testDotNotationPropMergesIntoExistingNestedStructure(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => [
                'user' => [
                    'name' => 'Jonathan Reinink',
                    'email' => 'jonathan@example.com',
                ],
            ],
            'auth.user.permissions' => fn () => ['edit-posts', 'delete-posts'],
        ]);

        $this->assertSame('Jonathan Reinink', $page['props']['auth']['user']['name']);
        $this->assertSame('jonathan@example.com', $page['props']['auth']['user']['email']);
        $this->assertSame(['edit-posts', 'delete-posts'], $page['props']['auth']['user']['permissions']);
        $this->assertArrayNotHasKey('auth.user.permissions', $page['props']);
    }

    public function testDotNotationPropMergesWhenParentIsAClosure(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => fn () => [
                'user' => [
                    'name' => 'Jonathan Reinink',
                    'email' => 'jonathan@example.com',
                ],
            ],
            'auth.user.permissions' => fn () => ['edit-posts', 'delete-posts'],
        ]);

        $this->assertSame('Jonathan Reinink', $page['props']['auth']['user']['name']);
        $this->assertSame('jonathan@example.com', $page['props']['auth']['user']['email']);
        $this->assertSame(['edit-posts', 'delete-posts'], $page['props']['auth']['user']['permissions']);
    }

    public function testExcludedDotPropsAndIntermediateParentsAreNotResolved(): void
    {
        $parentResolutions = 0;
        $dotResolutions = 0;

        $page = $this->makePage($this->makePartialRequest('other'), [
            'other' => 'included',
            'auth' => function () use (&$parentResolutions): array {
                ++$parentResolutions;

                return ['user' => ['name' => 'Jonathan']];
            },
            'auth.user.permissions' => function () use (&$dotResolutions): array {
                ++$dotResolutions;

                return ['edit-posts'];
            },
        ]);

        $this->assertSame(['other' => 'included'], $page['props']);
        $this->assertSame(0, $parentResolutions);
        $this->assertSame(0, $dotResolutions);
    }

    public function testIncludedDotPropAndIntermediateParentAreResolvedOnce(): void
    {
        $parentResolutions = 0;
        $dotResolutions = 0;

        $page = $this->makePage($this->makePartialRequest('auth.user.permissions'), [
            'auth' => function () use (&$parentResolutions): array {
                ++$parentResolutions;

                return ['user' => ['name' => 'Jonathan']];
            },
            'auth.user.permissions' => function () use (&$dotResolutions): array {
                ++$dotResolutions;

                return ['edit-posts'];
            },
        ]);

        $this->assertSame(['edit-posts'], $page['props']['auth']['user']['permissions']);
        $this->assertSame(1, $parentResolutions);
        $this->assertSame(1, $dotResolutions);
    }

    public function testDotNotationAlwaysPropIsIncludedWhenNotRequested(): void
    {
        $page = $this->makePage($this->makePartialRequest('other'), [
            'other' => 'included',
            'auth.errors' => Inertia::always(fn () => ['name' => 'required']),
        ]);

        $this->assertSame('included', $page['props']['other']);
        $this->assertSame(['name' => 'required'], $page['props']['auth']['errors']);
        $this->assertArrayNotHasKey('auth.errors', $page['props']);
    }

    public function testDotNotationArrayContainingAlwaysPropIsUnpackedWhenNotRequested(): void
    {
        $profileResolutions = 0;

        $page = $this->makePage($this->makePartialRequest('other'), [
            'other' => 'included',
            'auth.user' => [
                'profile' => function () use (&$profileResolutions): array {
                    ++$profileResolutions;

                    return ['name' => 'Jonathan'];
                },
                'errors' => Inertia::always(fn () => ['name' => 'required']),
            ],
        ]);

        $this->assertSame('included', $page['props']['other']);
        $this->assertSame(['name' => 'required'], $page['props']['auth']['user']['errors']);
        $this->assertArrayNotHasKey('profile', $page['props']['auth']['user']);
        $this->assertArrayNotHasKey('auth.user', $page['props']);
        $this->assertSame(0, $profileResolutions);
    }

    public function testDotNotationOptionalPropIsExcludedFromInitialLoad(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'auth' => [
                'user' => [
                    'name' => 'Jonathan Reinink',
                    'email' => 'jonathan@example.com',
                ],
            ],
            'auth.user.permissions' => Inertia::optional(fn () => ['edit-posts', 'delete-posts']),
        ]);

        $this->assertSame('Jonathan Reinink', $page['props']['auth']['user']['name']);
        $this->assertSame('jonathan@example.com', $page['props']['auth']['user']['email']);
        $this->assertArrayNotHasKey('permissions', $page['props']['auth']['user']);
        $this->assertArrayNotHasKey('auth.user.permissions', $page['props']);
    }

    public function testDotNotationOptionalPropIsIncludedOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('auth.user.permissions'), [
            'auth' => [
                'user' => [
                    'name' => 'Jonathan Reinink',
                    'email' => 'jonathan@example.com',
                ],
            ],
            'auth.user.permissions' => Inertia::optional(fn () => ['edit-posts', 'delete-posts']),
        ]);

        $this->assertSame(['edit-posts', 'delete-posts'], $page['props']['auth']['user']['permissions']);
        $this->assertArrayNotHasKey('auth.user.permissions', $page['props']);
    }

    public function testOptionalPropsInsideIndexedArraysAreResolvedOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('foos'), [
            'foos' => [
                [
                    'name' => 'First',
                    'bar' => Inertia::optional(fn () => 'expensive-data-1'),
                ],
                [
                    'name' => 'Second',
                    'bar' => Inertia::optional(fn () => 'expensive-data-2'),
                ],
            ],
        ]);

        $this->assertSame('First', $page['props']['foos'][0]['name']);
        $this->assertSame('expensive-data-1', $page['props']['foos'][0]['bar']);
        $this->assertSame('Second', $page['props']['foos'][1]['name']);
        $this->assertSame('expensive-data-2', $page['props']['foos'][1]['bar']);
    }

    public function testOptionalPropsInsideIndexedArraysAreExcludedFromInitialLoad(): void
    {
        $resolved = false;

        $page = $this->makePage(Request::create('/'), [
            'foos' => [
                [
                    'name' => 'First',
                    'bar' => Inertia::optional(function () use (&$resolved) {
                        $resolved = true;

                        return 'expensive-data';
                    }),
                ],
            ],
        ]);

        $this->assertSame('First', $page['props']['foos'][0]['name']);
        $this->assertArrayNotHasKey('bar', $page['props']['foos'][0]);
        $this->assertFalse($resolved, 'OptionalProp closure should not be resolved on initial load');
    }

    public function testDeferredPropsInsideClosureAreExcludedFromInitialLoad(): void
    {
        $notificationsResolved = false;
        $rolesResolved = false;

        $page = $this->makePage(Request::create('/'), [
            'auth' => fn () => [
                'user' => [
                    'name' => 'Jonathan Reinink',
                    'email' => 'jonathan@example.com',
                ],
                'notifications' => Inertia::defer(function () use (&$notificationsResolved) {
                    $notificationsResolved = true;

                    return ['You have a new follower'];
                }),
                'roles' => Inertia::defer(function () use (&$rolesResolved) {
                    $rolesResolved = true;

                    return ['admin'];
                }),
            ],
        ]);

        $this->assertSame('Jonathan Reinink', $page['props']['auth']['user']['name']);
        $this->assertArrayNotHasKey('notifications', $page['props']['auth']);
        $this->assertArrayNotHasKey('roles', $page['props']['auth']);
        $this->assertSame(['default' => ['auth.notifications', 'auth.roles']], $page['deferredProps']);
        $this->assertFalse($notificationsResolved, 'DeferProp closure should not be resolved on initial load'); // @phpstan-ignore method.impossibleType
        $this->assertFalse($rolesResolved, 'DeferProp closure should not be resolved on initial load'); // @phpstan-ignore method.impossibleType
    }

    public function testDeferredPropsInsideClosureAreResolvedOnPartialRequest(): void
    {
        $page = $this->makePage($this->makePartialRequest('auth.notifications,auth.roles'), [
            'auth' => fn () => [
                'user' => [
                    'name' => 'Jonathan Reinink',
                    'email' => 'jonathan@example.com',
                ],
                'notifications' => Inertia::defer(fn () => ['You have a new follower']),
                'roles' => Inertia::defer(fn () => ['admin']),
            ],
        ]);

        $this->assertSame(['You have a new follower'], $page['props']['auth']['notifications']);
        $this->assertSame(['admin'], $page['props']['auth']['roles']);
    }

    public function testArraysMatchingCallableSyntaxAreNotInvoked(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'job' => [
                'name' => 'Import',
                'fields' => ['Context', 'comment'],
            ],
        ]);

        $this->assertSame(['Context', 'comment'], $page['props']['job']['fields']);
    }

    public function testRecorderIsNotInvokedWhileResolvingPropsWhenDevtoolsIsDisabled(): void
    {
        config()->set('inertia.devtools.enabled', false);

        $spy = new class extends RequestRecorder {
            public int $calls = 0;

            /**
             * Count the resolved prop.
             */
            public function propResolved(string $path, mixed $prop): void
            {
                ++$this->calls;
            }

            /**
             * Count the rescued prop.
             */
            public function propRescued(string $path, mixed $prop): void
            {
                ++$this->calls;
            }
        };

        $this->app->instance(RequestRecorder::class, $spy);

        $this->makePage(Request::create('/'), [
            'auth' => fn (): array => ['user' => 'Jane'],
            'team' => 'Acme',
        ]);

        $this->assertSame(0, $spy->calls);
    }

    public function testRecorderIsInvokedWhileResolvingPropsWhenDevtoolsIsEnabled(): void
    {
        config()->set('inertia.devtools.enabled', true);

        $spy = new class extends RequestRecorder {
            public int $calls = 0;

            /**
             * Count the resolved prop.
             */
            public function propResolved(string $path, mixed $prop): void
            {
                ++$this->calls;
            }

            /**
             * Count the rescued prop.
             */
            public function propRescued(string $path, mixed $prop): void
            {
                ++$this->calls;
            }
        };

        $this->app->instance(RequestRecorder::class, $spy);

        $this->makePage(Request::create('/'), [
            'auth' => fn (): array => ['user' => 'Jane'],
            'team' => 'Acme',
        ]);

        $this->assertGreaterThan(0, $spy->calls);
    }

    public function testItDescendsIntoJsonSerializableProps(): void
    {
        $dto = new class implements JsonSerializable {
            /**
             * Get the JSON serializable representation of the object.
             */
            public function jsonSerialize(): array
            {
                return ['nested' => ['name' => 'John']];
            }
        };

        $page = $this->makePage(Request::create('/'), ['dto' => $dto]);

        $this->assertSame(['nested' => ['name' => 'John']], $page['props']['dto']);
    }

    public function testPropTypesNestedInJsonSerializablePropsAreResolved(): void
    {
        $props = fn (): array => [
            'dto' => new class implements JsonSerializable {
                /**
                 * Get the JSON serializable representation of the object.
                 */
                public function jsonSerialize(): array
                {
                    return ['thing' => Inertia::defer(fn (): string => 'deferred value'), 'plain' => 1];
                }
            },
        ];

        $page = $this->makePage(Request::create('/'), $props());

        $this->assertSame(['plain' => 1], $page['props']['dto']);
        $this->assertSame(['default' => ['dto.thing']], $page['deferredProps']);

        $request = Request::create('/');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'TestComponent']);
        $request->headers->add(['X-Inertia-Partial-Data' => 'dto.thing']);

        $page = $this->makePage($request, $props());

        $this->assertSame('deferred value', $page['props']['dto']['thing']);
    }

    public function testBigIntegersInsidePlainObjectsAreWrappedWhenEnabled(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'object' => (object) ['id' => 900719925474099988],
            'dto' => new class implements JsonSerializable {
                /**
                 * Get the JSON serializable representation of the object.
                 */
                public function jsonSerialize(): array
                {
                    return ['id' => 900719925474099988];
                }
            },
        ], preserveBigIntegers: true);

        $this->assertSame(['$bigint' => '900719925474099988'], $page['props']['object']->id);
        $this->assertSame(['$bigint' => '900719925474099988'], $page['props']['dto']['id']);
    }

    public function testBigIntegersInsideArbitraryObjectsAreWrappedWhenEnabled(): void
    {
        $money = new class {
            public int $cents = 900719925474099988;
        };

        $page = $this->makePage(Request::create('/'), [
            'money' => $money,
            'nested' => ['money' => $money],
            'wrapped' => (object) ['money' => $money],
            'collection' => collect(['id' => 900719925474099988]),
        ], preserveBigIntegers: true);

        $marker = ['$bigint' => '900719925474099988'];

        $this->assertSame($marker, $page['props']['money']->cents);
        $this->assertSame($marker, $page['props']['nested']['money']->cents);
        $this->assertSame($marker, $page['props']['wrapped']->money->cents);
        $this->assertSame($marker, $page['props']['collection']['id']);
    }

    public function testSelfReferencingObjectsDoNotRecurseForever(): void
    {
        $cyclic = new stdClass;
        $cyclic->id = 900719925474099988;
        $cyclic->self = $cyclic;

        // The root view throws when json_encode rejects the cycle left in the page.
        try {
            $this->makePage(Request::create('/'), ['cyclic' => $cyclic], preserveBigIntegers: true);
            $this->fail('Expected json_encode to reject the self-reference.');
        } catch (ViewException $exception) {
            $this->assertStringContainsString('Recursion detected', $exception->getMessage());
        }

        $this->assertSame(['$bigint' => '900719925474099988'], InertiaState::current()->page['props']['cyclic']->id);
    }

    public function testAnObjectSharedByTwoPropsIsWrappedInBoth(): void
    {
        $shared = (object) ['id' => 900719925474099988];

        $page = $this->makePage(Request::create('/'), ['first' => $shared, 'second' => $shared], preserveBigIntegers: true);

        $marker = ['$bigint' => '900719925474099988'];

        $this->assertSame($marker, $page['props']['first']->id);
        $this->assertSame($marker, $page['props']['second']->id);
    }

    public function testInternalObjectsKeepTheShapeJsonEncodeGivesThem(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'when' => ['at' => new DateTimeImmutable('2020-01-01T00:00:00Z')],
            'extended' => new class('2020-01-01T00:00:00Z') extends DateTimeImmutable {},
            'bag' => new class(['name' => 'John']) extends ArrayObject {},
        ], preserveBigIntegers: true);

        // Walking its properties would flatten it to an empty object.
        $this->assertSame(
            '{"at":{"date":"2020-01-01 00:00:00.000000","timezone_type":2,"timezone":"Z"}}',
            json_encode($page['props']['when'])
        );
        $this->assertSame(
            '{"date":"2020-01-01 00:00:00.000000","timezone_type":2,"timezone":"Z"}',
            json_encode($page['props']['extended'])
        );
        $this->assertSame('{"name":"John"}', json_encode($page['props']['bag']));
    }

    public function testPureEnumsAreLeftForJsonEncodeToReject(): void
    {
        // Walking its properties would invent a JSON representation it does not have.
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Non-backed enums have no default serialization');

        $this->makePage(Request::create('/'), ['status' => UnitEnum::Index], preserveBigIntegers: true);
    }

    public function testBigIntegersInsideNumericallyKeyedObjectsAreWrappedWithoutChangingTheShape(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'list' => (object) ['0' => 900719925474099988, '1' => -900719925474099988],
            'empty' => new stdClass,
        ], preserveBigIntegers: true);

        $this->assertInstanceOf(stdClass::class, $page['props']['list']);
        $this->assertSame(
            '{"0":{"$bigint":"900719925474099988"},"1":{"$bigint":"-900719925474099988"}}',
            json_encode($page['props']['list'])
        );
        $this->assertSame('{}', json_encode($page['props']['empty']));
    }

    public function testBigIntegersAreWrappedWhenEnabled(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'safe' => 42,
            'boundary' => 9007199254740991,
            'negativeBoundary' => -9007199254740991,
            'big' => 900719925474099988,
            'negative' => -900719925474099988,
            'nested' => ['deep' => [900719925474099988, 2]],
        ], preserveBigIntegers: true);

        $this->assertSame(42, $page['props']['safe']);
        $this->assertSame(9007199254740991, $page['props']['boundary']);
        $this->assertSame(-9007199254740991, $page['props']['negativeBoundary']);
        $this->assertSame(['$bigint' => '900719925474099988'], $page['props']['big']);
        $this->assertSame(['$bigint' => '-900719925474099988'], $page['props']['negative']);
        $this->assertSame(['$bigint' => '900719925474099988'], $page['props']['nested']['deep'][0]);
        $this->assertSame(2, $page['props']['nested']['deep'][1]);
    }

    public function testBigIntegersAreNotWrappedWhenDisabled(): void
    {
        $page = $this->makePage(Request::create('/'), [
            'big' => 900719925474099988,
        ]);

        $this->assertSame(900719925474099988, $page['props']['big']);
    }

    /**
     * Resolve the given props through the Inertia response and return the page data.
     *
     * @param array<array-key, mixed> $props
     * @return array<string, mixed>
     */
    protected function makePage(Request $request, array $props, bool $preserveBigIntegers = false): array
    {
        $response = new Response('TestComponent', [], $props, 'app', '123', preserveBigIntegers: $preserveBigIntegers);
        $response = $response->toResponse($request);

        if ($response instanceof JsonResponse) {
            return $response->getData(true);
        }

        /** @var BaseResponse $response */
        $view = $response->getOriginalContent();

        return $view->getData()['page'];
    }

    /**
     * Create a partial Inertia request for the given props.
     */
    protected function makePartialRequest(string $only): Request
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'TestComponent']);
        $request->headers->add(['X-Inertia-Partial-Data' => $only]);

        return $request;
    }

    /**
     * Create a scroll metadata provider for testing.
     */
    protected function makeScrollMetadata(): ProvidesScrollMetadata
    {
        return new class implements ProvidesScrollMetadata {
            public function getPageName(): string
            {
                return 'page';
            }

            public function getPreviousPage(): ?int
            {
                return null;
            }

            public function getNextPage(): int
            {
                return 2;
            }

            public function getCurrentPage(): int
            {
                return 1;
            }
        };
    }
}
