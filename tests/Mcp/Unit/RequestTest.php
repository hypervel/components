<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit;

use Hypervel\Foundation\Auth\User;
use Hypervel\Mcp\Request;
use Hypervel\Support\Facades\Auth;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\ValidationException;

class RequestTest extends TestCase
{
    public function testMayReturnAllData(): void
    {
        $request = new Request([
            'name' => 'Alice',
            'age' => 30,
            'city' => 'Wonderland',
        ]);

        $this->assertSame([
            'name' => 'Alice',
            'age' => 30,
            'city' => 'Wonderland',
        ], $request->all());
    }

    public function testMayReturnSpecificSetOfKeys(): void
    {
        $request = new Request([
            'name' => 'Alice',
            'age' => 30,
            'city' => 'Wonderland',
        ]);

        $this->assertSame([
            'name' => 'Alice',
            'age' => 30,
        ], $request->all(['name', 'age']));
        $this->assertSame([
            'name' => 'Alice',
        ], $request->all('name'));
    }

    public function testInteractWithData(): void
    {
        $request = new Request([
            'name' => 'Alice',
            'age' => 30,
            'city' => 'Wonderland',
        ]);

        $this->assertSame('Alice', $request->get('name'));
        $this->assertTrue($request->filled('name'));
        $this->assertFalse($request->filled('country'));
        $this->assertSame('Wonderland', $request->string('city')->value());
        $this->assertSame(0, $request->integer('city'));
    }

    public function testRetrievesNestedInputUsingDotNotation(): void
    {
        $request = new Request([
            'user' => [
                'name' => 'Alice',
                'email' => 'alice@example.com',
            ],
            'products' => [
                ['name' => 'Widget'],
                ['name' => 'Gadget'],
            ],
        ]);

        $this->assertSame('Alice', $request->get('user.name'));
        $this->assertSame('fallback', $request->get('user.missing', 'fallback'));
        $this->assertSame('Widget', $request->get('products.0.name'));
        $this->assertSame(['Widget', 'Gadget'], $request->get('products.*.name'));
        $this->assertSame('alice@example.com', $request->string('user.email')->value());
        $this->assertTrue($request->filled('user.name'));
        $this->assertFalse($request->filled('user.country'));
    }

    public function testDoesNotResolveLiteralDottedKeys(): void
    {
        $request = new Request([
            'limit.items' => 5,
            'limit' => ['items' => 10],
        ]);

        $this->assertSame(10, $request->get('limit.items'));
        $this->assertSame(10, $request->get('limit.items', 'fallback'));
        $this->assertNull((new Request(['limit.items' => 5]))->get('limit.items'));
        $this->assertSame(['limit.items' => 5], (new Request(['limit.items' => 5]))->all());
    }

    public function testMayBeReturnedAsArray(): void
    {
        $request = new Request([
            'name' => 'Alice',
            'age' => 30,
            'city' => 'Wonderland',
        ]);

        $this->assertSame([
            'name' => 'Alice',
            'age' => 30,
            'city' => 'Wonderland',
        ], $request->toArray());
    }

    public function testMayReturnTheCurrentLoggedInUser(): void
    {
        $user = new class extends User {};

        Auth::setUser($user);

        $request = new Request;

        $this->assertSame($user, $request->user());
    }

    public function testMayReturnNullIfNoUserIsLoggedIn(): void
    {
        $request = new Request;

        $this->assertNull($request->user());
    }

    public function testValidatesAndReturnsOnlyValidatedDataOnSuccess(): void
    {
        $request = new Request([
            'email' => 'alice@example.com',
            'extra' => 'keep out',
        ]);

        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $this->assertSame([
            'email' => 'alice@example.com',
        ], $validated);
    }

    public function testThrowsValidationExceptionWithCustomMessagesAndAttributes(): void
    {
        $request = new Request([
            'email' => 'not-an-email',
        ]);

        $messages = [
            'email.email' => 'Please provide a valid :attribute.',
        ];

        $attributes = [
            'email' => 'email address',
        ];

        $closure = function () use ($request, $messages, $attributes): void {
            $request->validate([
                'email' => 'required|email',
            ], $messages, $attributes);
        };

        $this->expectException(ValidationException::class);
        $closure();
    }

    public function testCanGetUriWhenSetViaConstructor(): void
    {
        $request = new Request(
            arguments: ['name' => 'Alice'],
            meta: ['key' => 'value'],
            uri: 'file://resources/example'
        );

        $this->assertSame('file://resources/example', $request->uri());
    }

    public function testReturnsNullForUriWhenNotSetViaConstructor(): void
    {
        $request = new Request(
            arguments: ['name' => 'Alice'],
            meta: ['key' => 'value']
        );

        $this->assertNull($request->uri());
    }

    public function testReturnsNullForUriWhenExplicitlySetToNullInConstructor(): void
    {
        $request = new Request(
            arguments: ['name' => 'Alice']
        );

        $this->assertNull($request->uri());
    }

    public function testCanSetUriUsingSetUriMethod(): void
    {
        $request = new Request(['name' => 'Alice']);

        $request->setUri('file://resources/test');

        $this->assertSame('file://resources/test', $request->uri());
    }

    public function testCanUpdateUriUsingSetUriMethod(): void
    {
        $request = new Request(
            arguments: ['name' => 'Alice'],
            uri: 'file://resources/original'
        );

        $request->setUri('file://resources/updated');

        $this->assertSame('file://resources/updated', $request->uri());
    }

    public function testCanSetUriToNullUsingSetUriMethod(): void
    {
        $request = new Request(
            arguments: ['name' => 'Alice'],
            uri: 'file://resources/example'
        );

        $result = $request->setUri(null);

        $this->assertNull($request->uri());
        $this->assertNull($result);
    }

    public function testSupportsMethodChainingWithMergeAndSetUri(): void
    {
        $request = new Request(['name' => 'Alice']);

        $request->merge(['age' => 30])->setUri('file://resources/test');

        $this->assertSame('file://resources/test', $request->uri());
        $this->assertSame('Alice', $request->get('name'));
        $this->assertSame(30, $request->get('age'));
    }

    public function testDistinguishesNestedNumericObjectsFromLists(): void
    {
        $source = '{"params":{"arguments":{"calls":[{"arguments":{"0":"value"}},{"arguments":["value"]}]}}}';
        $request = new Request(json_decode($source, true, flags: JSON_THROW_ON_ERROR)['params']['arguments']);
        $request->setSource($source);

        $this->assertTrue($request->isObject('calls.0.arguments'));
        $this->assertFalse($request->isObject('calls.1.arguments'));

        $request->setSource('{"params":{"arguments":{"calls":[{"arguments":["value"]}]}}}');
        $this->assertFalse($request->isObject('calls.0.arguments'));
    }

    public function testDirectRequestsUsePhpObjectSemantics(): void
    {
        $request = new Request(['object' => (object) ['value'], 'map' => ['name' => 'value'], 'empty' => [], 'list' => ['value'], 'scalar' => 'value']);

        $this->assertTrue($request->isObject());
        $this->assertTrue($request->isObject('object'));
        $this->assertTrue($request->isObject('map'));
        $this->assertTrue($request->isObject('empty'));
        $this->assertFalse($request->isObject('list'));
        $this->assertFalse($request->isObject('scalar'));
    }
}
