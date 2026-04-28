<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response as BaseResponse;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\MergeProp;
use Hypervel\Inertia\ProvidesInertiaProperties;
use Hypervel\Inertia\ProvidesScrollMetadata;
use Hypervel\Inertia\RenderContext;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\ScrollProp;

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

    /**
     * Resolve the given props through the Inertia response and return the page data.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function makePage(Request $request, array $props): array
    {
        $response = new Response('TestComponent', [], $props, 'app', '123');
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
