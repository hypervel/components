<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\AnonymousMiddlewareController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\AuditEntryController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\BarrelCollisionController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\BarrelCollisionController\NestedController as BarrelCollisionNestedController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\DisallowedMethodNameController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\DomainController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\InvokableController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\InvokablePlusController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\KeyController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\ModelBindingController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\NamedInvokableController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\NavigationItemController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\Nested\NestedController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\OptionalController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\ParameterNameController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\PostController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\ReverseBarrelCollisionController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\ReverseBarrelCollisionController\NestedController as ReverseBarrelCollisionNestedController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\TwoRoutesSameActionController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\UrlDefaultsController;
use Hypervel\Tests\Wayfinder\Fixtures\Middleware\UrlDefaultsMiddleware;
use Hypervel\Tests\Wayfinder\Fixtures\Prism\Prism\Http\Controllers\PrismChatController;

Route::get('/', fn () => 'Home')->name('home');

Route::get('/closure', fn () => 'ok');
Route::get('/export/{report}/{export}', fn () => 'Export')->name('export');
Route::get('/invokable-controller', InvokableController::class);
Route::get('/named-invokable-controller', NamedInvokableController::class)->name('invokable');
Route::get('/invokable-plus-controller', InvokablePlusController::class);
Route::post('/invokable-plus-controller', [InvokablePlusController::class, 'store']);
Route::get('/invokable-plus-controller/form-name', [InvokablePlusController::class, 'InvokablePlusControllerForm']);

Route::get('/barrel-collision', [BarrelCollisionController::class, 'show']);
Route::get('/barrel-collision/nested', [BarrelCollisionNestedController::class, 'index']);
Route::get('/reverse-barrel-collision/nested', [ReverseBarrelCollisionNestedController::class, 'index']);
Route::get('/reverse-barrel-collision', [ReverseBarrelCollisionController::class, 'show']);

Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create');
Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show');
Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit');
Route::patch('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

Route::get('/dashboard', function () {
    return 'Dashboard';
})->name('dashboard');

Route::get('/invalid-js-name', function () {
    return 'Invalid JS name';
})->name('invalid#js@name');

Route::post('/optional/{parameter?}', [OptionalController::class, 'optional'])->name('optional');
Route::post('/many-optional/{one?}/{two?}/{three?}', [OptionalController::class, 'manyOptional']);
Route::post('/required-with-optional/{required}/{one?}/{two?}', [OptionalController::class, 'requiredWithOptional']);
Route::get('/literal/[ draft ]/(bar )/ .replace', fn () => 'literal')->name('literal.syntax');

Route::middleware(UrlDefaultsMiddleware::class)
    ->get('/users/{user}', [ModelBindingController::class, 'show']);
Route::get('/users/active/{user:active}', [ModelBindingController::class, 'active']);
Route::get('/users/price/{user:price}', [ModelBindingController::class, 'price']);
Route::get('/users/reference/{user:reference}', [ModelBindingController::class, 'reference']);
Route::get('/optional-users/{user?}/{filter?}', [ModelBindingController::class, 'optional']);
Route::get('/audit-entries/{audit_entry}', [AuditEntryController::class, 'show']);

Route::middleware(UrlDefaultsMiddleware::class)->post('/with-defaults/{locale}', [UrlDefaultsController::class, 'onlyDefaults']);
Route::middleware(UrlDefaultsMiddleware::class)->post('/with-defaults/{locale}/also/{timezone}', [UrlDefaultsController::class, 'mixedDefaults']);
Route::middleware(UrlDefaultsMiddleware::class)->get(
    '/parsed-defaults/{locale}/{signed}/{ratio}/{enabled}/{disabled}/{dynamic}/{secondary}/{computed}/{literalNull}/{unsupported}/{neighbor}',
    [UrlDefaultsController::class, 'parsedDefaults'],
);

Route::get('/keys/{key}', [KeyController::class, 'show']);
Route::get('/keys/{key:uuid}/edit', [KeyController::class, 'edit']);
Route::get('/keys/{key}/external', [KeyController::class, 'external'])
    ->setBindingFields(['key' => 'external-id']);

Route::get('/parameter-names/{camelCase}/camel', [ParameterNameController::class, 'camel']);
Route::get('/parameter-names/{StudlyCase}/studly', [ParameterNameController::class, 'studly']);
Route::get('/parameter-names/{snake_case}/snake', [ParameterNameController::class, 'snake']);
Route::get('/parameter-names/{SCREAMING_SNAKE_CASE}/screaming-snake', [ParameterNameController::class, 'screamingSnake']);

Route::domain('example.test')->get('/fixed-domain/{param}', [DomainController::class, 'fixedDomain']);
Route::domain('{defaultDomain}.au')->get('/default-parameters-domain/{param}', [DomainController::class, 'defaultParametersDomain']);
Route::domain('{dynamic}.test')
    ->middleware(UrlDefaultsMiddleware::class)
    ->get('/dynamic-parameters-domain/{param}', [DomainController::class, 'dynamicParametersDomain']);

Route::get('/nested/controller', [NestedController::class, 'nested']);
Route::get('/nested/controller/child', [NestedController::class, 'child'])->name('nested.child');
Route::get('/nested/controller/child/grandchild', [NestedController::class, 'grandchild'])->name('nested.child.grandchild');
Route::get('/nested/foo-bar', fn () => 'foo-bar')->name('nested.foo-bar.index');
Route::get('/nested/foo-bar-camel', fn () => 'fooBar')->name('nested.fooBar.index');
Route::get('/named-reverse/child/grandchild', fn () => 'grandchild')->name('named-reverse.child.grandchild');
Route::get('/named-reverse/child', fn () => 'child')->name('named-reverse.child');
Route::get('/reports/index/daily', fn () => 'daily')->name('reports.index.daily');

Route::get('/prism/chat', [PrismChatController::class, 'index']);

Route::get('/two-routes-one-action-1', [TwoRoutesSameActionController::class, 'same']);
Route::get('/two-routes-one-action-2', [TwoRoutesSameActionController::class, 'same']);
Route::get('/two-routes-one-action-same-uri', [TwoRoutesSameActionController::class, 'sameUri']);
Route::post('/two-routes-one-action-same-uri', [TwoRoutesSameActionController::class, 'sameUri']);
Route::match(['GET', 'POST'], '/two-routes-one-action-match', [TwoRoutesSameActionController::class, 'matched']);

Route::get('/disallowed/delete', [DisallowedMethodNameController::class, 'delete']);
Route::get('/disallowed/delete-method', [DisallowedMethodNameController::class, 'deleteMethod']);
Route::get('/disallowed/query-params', [DisallowedMethodNameController::class, 'queryParams']);
Route::get('/disallowed/apply-url-defaults', [DisallowedMethodNameController::class, 'applyUrlDefaults']);
Route::get('/disallowed/validate-parameters', [DisallowedMethodNameController::class, 'validateParameters']);
Route::get('/disallowed/format-route-parameter', [DisallowedMethodNameController::class, 'formatRouteParameter']);
Route::get('/disallowed/show', [DisallowedMethodNameController::class, 'show']);
Route::get('/disallowed/show-form', [DisallowedMethodNameController::class, 'showForm']);
Route::get('/disallowed/eval', [DisallowedMethodNameController::class, 'eval']);
Route::get('/disallowed/arguments', [DisallowedMethodNameController::class, 'arguments']);
Route::get('/disallowed/controller-name', [DisallowedMethodNameController::class, 'DisallowedMethodNameController']);
Route::get('/disallowed/controller-form-name', [DisallowedMethodNameController::class, 'DisallowedMethodNameControllerForm']);
Route::get('/disallowed/404', [DisallowedMethodNameController::class, '404'])->name('disallowed.404');
Route::get('/disallowed/2fa', [DisallowedMethodNameController::class, '2fa'])->name('2fa.disallowed');
Route::get('/disallowed/default', [DisallowedMethodNameController::class, 'default'])->name('default.login');
Route::get('/navigation-items/{item}/options', [NavigationItemController::class, 'options']);

Route::get('/named-collision/foo-bar', fn () => 'foo-bar')->name('collision.foo-bar');
Route::get('/named-collision/foo-bar-camel', fn () => 'fooBar')->name('collision.fooBar');
Route::get('/named-collision/foo-bar-two', fn () => 'fooBar2')->name('collision.fooBar2');
Route::get('/named-collision/numeric-key', fn () => 'numeric')->name('numeric-key.1e3');
Route::get('/named-form/show', fn () => 'show')->name('form-collision.show');
Route::get('/named-form/show-form', fn () => 'showForm')->name('form-collision.showForm');
Route::get('/named-import/query-params', fn () => 'queryParams')->name('import-collision.queryParams');
Route::get('/named-import/apply-url-defaults', fn () => 'applyUrlDefaults')->name('import-collision.applyUrlDefaults');
Route::get('/mixed-form/show/child', fn () => 'child')->name('mixed-form.show.child');
Route::get('/mixed-form/show-form', fn () => 'showForm')->name('mixed-form.showForm');
Route::get('/reverse-mixed-form/show-form', fn () => 'showForm')->name('reverse-mixed-form.showForm');
Route::get('/reverse-mixed-form/show/child', fn () => 'child')->name('reverse-mixed-form.show.child');
Route::get('/form-shadow/edit', fn () => 'edit')->name('form-shadow.edit');
Route::get('/form-shadow/edit-form/child', fn () => 'child')->name('form-shadow.editForm.child');
Route::get('/reverse-form-shadow/edit-form/child', fn () => 'child')->name('reverse-form-shadow.editForm.child');
Route::get('/reverse-form-shadow/edit', fn () => 'edit')->name('reverse-form-shadow.edit');
Route::get('/barrel-alias/foo/child', fn () => 'child')->name('barrel-alias.Foo.child');
Route::get('/barrel-alias/foo-form/child', fn () => 'child')->name('barrel-alias.FooForm.child');
Route::get('/named-strict/eval', fn () => 'eval')->name('strict.eval');
Route::get('/named-strict/arguments', fn () => 'arguments')->name('strict.arguments');
Route::get('/barrel/foo-bar', fn () => 'foo-bar')->name('foo-bar.index');
Route::get('/barrel/foo-bar-camel', fn () => 'fooBar')->name('fooBar.index');
Route::get('/barrel/foo-bar-two', fn () => 'fooBar2')->name('fooBar2.index');

Route::get('/anonymous-middleware', [AnonymousMiddlewareController::class, 'show']);

Route::get('/package-route', function () {
})->name('my-package::store');

Route::prefix('/api/v1')->name('api.v1.')->group(function () {
    Route::get('/tasks', fn () => 'ok')->name('tasks');

    Route::prefix('/tasks/{task}/task-status')->name('task-status.')->group(function () {
        Route::get('/', fn () => 'ok')->name('index');
    });
});
