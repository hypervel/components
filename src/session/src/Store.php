<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Session\Session;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\MessageBag;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Uri;
use Hypervel\Support\ViewErrorBag;
use RuntimeException;
use SessionHandlerInterface;
use stdClass;
use UnitEnum;

use function Hypervel\Support\enum_value;

class Store implements Session
{
    use Macroable;

    /**
     * The context key used to store the active session for the current request.
     */
    public const CONTEXT_KEY = '__session.store';

    /**
     * Context key for whether the session has been started.
     */
    public const STARTED_CONTEXT_KEY = '__session.store.started';

    /**
     * Context key for the session attributes.
     */
    public const ATTRIBUTES_CONTEXT_KEY = '__session.store.attributes';

    /**
     * Context key for the session ID.
     */
    public const ID_CONTEXT_KEY = '__session.store.id';

    /**
     * The length of session ID strings.
     */
    protected const SESSION_ID_LENGTH = 40;

    /**
     * Create a new session instance.
     *
     * @param string $name the session name
     * @param SessionHandlerInterface $handler the session handler implementation
     * @param string $serialization the session store's serialization strategy
     */
    public function __construct(
        protected string $name,
        protected SessionHandlerInterface $handler,
        ?string $id = null,
        protected string $serialization = 'php'
    ) {
        $this->setId($id);
    }

    /**
     * Start the session, reading the data from a handler.
     */
    public function start(): bool
    {
        $this->loadSession();

        if (! $this->has('_token')) {
            $this->regenerateToken();
        }

        return CoroutineContext::set(self::STARTED_CONTEXT_KEY, true);
    }

    /**
     * Get the session attributes.
     */
    protected function getAttributes(): array
    {
        return CoroutineContext::get(self::ATTRIBUTES_CONTEXT_KEY, []);
    }

    /**
     * Set the session attributes.
     */
    protected function setAttributes(array $attributes): void
    {
        CoroutineContext::set(self::ATTRIBUTES_CONTEXT_KEY, $attributes);
    }

    /**
     * Replace the session attributes.
     */
    protected function replaceAttributes(array $attributes): void
    {
        CoroutineContext::set(
            self::ATTRIBUTES_CONTEXT_KEY,
            array_replace(CoroutineContext::get(self::ATTRIBUTES_CONTEXT_KEY, []), $attributes)
        );
    }

    /**
     * Load the session data from the handler.
     */
    protected function loadSession(): void
    {
        $this->replaceAttributes($this->readFromHandler());

        $this->marshalErrorBag();
    }

    /**
     * Read the session data from the handler.
     */
    protected function readFromHandler(): array
    {
        if ($data = $this->handler->read($this->getId())) {
            if ($this->serialization === 'json') {
                $data = json_decode($this->prepareForUnserialize($data), true);
            } else {
                $data = @unserialize($this->prepareForUnserialize($data));
            }

            if ($data !== false && is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Prepare the raw string data from the session for unserialization.
     */
    protected function prepareForUnserialize(string $data): string
    {
        return $data;
    }

    /**
     * Marshal the ViewErrorBag when using JSON serialization for sessions.
     */
    protected function marshalErrorBag(): void
    {
        if ($this->serialization !== 'json' || $this->missing('errors')) {
            return;
        }

        $errorBag = new ViewErrorBag;

        foreach ($this->get('errors') as $key => $value) {
            $messageBag = new MessageBag($value['messages']);

            $errorBag->put($key, $messageBag->setFormat($value['format']));
        }

        $this->put('errors', $errorBag);
    }

    /**
     * Save the session data to storage.
     */
    public function save(): void
    {
        $this->ageFlashData();

        $this->prepareErrorBagForSerialization();

        $this->handler->write($this->getId(), $this->prepareForStorage(
            $this->serialization === 'json' ? json_encode($this->getAttributes()) : serialize($this->getAttributes())
        ));

        CoroutineContext::set(self::STARTED_CONTEXT_KEY, false);
    }

    /**
     * Prepare the ViewErrorBag instance for JSON serialization.
     */
    protected function prepareErrorBagForSerialization(): void
    {
        if ($this->serialization !== 'json' || $this->missing('errors')) {
            return;
        }

        $errors = [];

        foreach ($this->getAttributes()['errors']->getBags() as $key => $value) {
            $errors[$key] = [
                'format' => $value->getFormat(),
                'messages' => $value->getMessages(),
            ];
        }

        $this->replaceAttributes(['errors' => $errors]);
    }

    /**
     * Prepare the serialized session data for storage.
     */
    protected function prepareForStorage(string $data): string
    {
        return $data;
    }

    /**
     * Age the flash data for the session.
     */
    public function ageFlashData(): void
    {
        $this->forget($this->get('_flash.old', []));

        $this->put('_flash.old', $this->get('_flash.new', []));

        $this->put('_flash.new', []);
    }

    /**
     * Get all of the session data.
     */
    public function all(): array
    {
        return $this->getAttributes();
    }

    /**
     * Get a subset of the session data.
     */
    public function only(array $keys): array
    {
        return Arr::only($this->getAttributes(), array_map(enum_value(...), $keys));
    }

    /**
     * Get all the session data except for a specified array of items.
     */
    public function except(array $keys): array
    {
        return Arr::except($this->getAttributes(), array_map(enum_value(...), $keys));
    }

    /**
     * Checks if a key exists.
     */
    public function exists(array|UnitEnum|string $key): bool
    {
        $placeholder = new stdClass;

        return ! collect(is_array($key) ? $key : func_get_args())->contains(function ($key) use ($placeholder) {
            return $this->get($key, $placeholder) === $placeholder;
        });
    }

    /**
     * Determine if the given key is missing from the session data.
     */
    public function missing(array|UnitEnum|string $key): bool
    {
        return ! $this->exists($key);
    }

    /**
     * Determine if a key is present and not null.
     */
    public function has(array|UnitEnum|string $key): bool
    {
        return ! collect(is_array($key) ? $key : func_get_args())->contains(function ($key) {
            return is_null($this->get($key));
        });
    }

    /**
     * Determine if any of the given keys are present and not null.
     */
    public function hasAny(array|UnitEnum|string $key): bool
    {
        return collect(is_array($key) ? $key : func_get_args())->filter(function ($key) {
            return ! is_null($this->get($key));
        })->count() >= 1;
    }

    /**
     * Get an item from the session.
     */
    public function get(UnitEnum|string $key, mixed $default = null): mixed
    {
        return Arr::get($this->getAttributes(), enum_value($key), $default);
    }

    /**
     * Get the value of a given key and then forget it.
     */
    public function pull(UnitEnum|string $key, mixed $default = null): mixed
    {
        $attributes = $this->getAttributes();
        $result = Arr::pull($attributes, enum_value($key), $default);

        $this->setAttributes($attributes);

        return $result;
    }

    /**
     * Determine if the session contains old input.
     */
    public function hasOldInput(UnitEnum|string|null $key = null): bool
    {
        $old = $this->getOldInput($key);

        return is_null($key) ? count($old) > 0 : ! is_null($old);
    }

    /**
     * Get the requested item from the flashed input array.
     */
    public function getOldInput(UnitEnum|string|null $key = null, mixed $default = null): mixed
    {
        return Arr::get($this->get('_old_input', []), enum_value($key), $default);
    }

    /**
     * Replace the given session attributes entirely.
     */
    public function replace(array $attributes): void
    {
        $this->put($attributes);
    }

    /**
     * Put a key / value pair or array of key / value pairs in the session.
     */
    public function put(array|UnitEnum|string $key, mixed $value = null): void
    {
        if (! is_array($key)) {
            $key = [enum_value($key) => $value];
        }

        $attributes = $this->getAttributes();
        foreach ($key as $arrayKey => $arrayValue) {
            Arr::set($attributes, enum_value($arrayKey), $arrayValue);
        }

        $this->setAttributes($attributes);
    }

    /**
     * Get an item from the session, or store the default value.
     */
    public function remember(UnitEnum|string $key, Closure $callback): mixed
    {
        if (! is_null($value = $this->get($key))) {
            return $value;
        }

        return tap($callback(), function ($value) use ($key) {
            $this->put($key, $value);
        });
    }

    /**
     * Push a value onto a session array.
     */
    public function push(UnitEnum|string $key, mixed $value): void
    {
        $array = $this->get($key, []);

        $array[] = $value;

        $this->put($key, $array);
    }

    /**
     * Increment the value of an item in the session.
     */
    public function increment(UnitEnum|string $key, int $amount = 1): mixed
    {
        $this->put($key, $value = $this->get($key, 0) + $amount);

        return $value;
    }

    /**
     * Decrement the value of an item in the session.
     */
    public function decrement(UnitEnum|string $key, int $amount = 1): int
    {
        return $this->increment($key, $amount * -1);
    }

    /**
     * Flash a key / value pair to the session.
     */
    public function flash(UnitEnum|string $key, mixed $value = true): void
    {
        $key = enum_value($key);

        $this->put($key, $value);

        $this->push('_flash.new', $key);

        $this->removeFromOldFlashData([$key]);
    }

    /**
     * Flash a key / value pair to the session for immediate use.
     */
    public function now(UnitEnum|string $key, mixed $value): void
    {
        $key = enum_value($key);

        $this->put($key, $value);

        $this->push('_flash.old', $key);
    }

    /**
     * Reflash all of the session flash data.
     */
    public function reflash(): void
    {
        $this->mergeNewFlashes($this->get('_flash.old', []));

        $this->put('_flash.old', []);
    }

    /**
     * Reflash a subset of the current flash data.
     *
     * @param array|mixed $keys
     */
    public function keep(mixed $keys = null): void
    {
        $this->mergeNewFlashes($keys = is_array($keys) ? $keys : func_get_args());

        $this->removeFromOldFlashData($keys);
    }

    /**
     * Merge new flash keys into the new flash array.
     */
    protected function mergeNewFlashes(array $keys): void
    {
        $values = array_unique(array_merge($this->get('_flash.new', []), $keys));

        $this->put('_flash.new', $values);
    }

    /**
     * Remove the given keys from the old flash data.
     */
    protected function removeFromOldFlashData(array $keys): void
    {
        $this->put('_flash.old', array_diff($this->get('_flash.old', []), $keys));
    }

    /**
     * Flash an input array to the session.
     */
    public function flashInput(array $value): void
    {
        $this->flash('_old_input', $value);
    }

    /**
     * Get the session cache instance.
     */
    public function cache(): CacheRepository
    {
        return Cache::store('session');
    }

    /**
     * Remove an item from the session, returning its value.
     */
    public function remove(UnitEnum|string $key): mixed
    {
        $attributes = $this->getAttributes();
        $result = Arr::pull($attributes, enum_value($key));

        $this->setAttributes($attributes);

        return $result;
    }

    /**
     * Remove one or many items from the session.
     */
    public function forget(array|UnitEnum|string $keys): void
    {
        $attributes = $this->getAttributes();
        Arr::forget($attributes, collect((array) $keys)->map(fn ($key) => enum_value($key))->all());

        $this->setAttributes($attributes);
    }

    /**
     * Remove all of the items from the session.
     */
    public function flush(): void
    {
        $this->setAttributes([]);
    }

    /**
     * Flush the session data and regenerate the ID.
     */
    public function invalidate(): bool
    {
        $this->flush();

        return $this->migrate(true);
    }

    /**
     * Generate a new session identifier.
     */
    public function regenerate(bool $destroy = false): bool
    {
        return tap($this->migrate($destroy), function () {
            $this->regenerateToken();
        });
    }

    /**
     * Generate a new session ID for the session.
     */
    public function migrate(bool $destroy = false): bool
    {
        if ($destroy) {
            $this->handler->destroy($this->getId());
        }

        $this->setExists(false);

        $this->setId($this->generateSessionId());

        return true;
    }

    /**
     * Determine if the session has been started.
     */
    public function isStarted(): bool
    {
        return CoroutineContext::get(self::STARTED_CONTEXT_KEY, false);
    }

    /**
     * Flush per-request session state from context.
     */
    public static function flushState(): void
    {
        CoroutineContext::forget(self::CONTEXT_KEY);
        CoroutineContext::forget(self::STARTED_CONTEXT_KEY);
        CoroutineContext::forget(self::ID_CONTEXT_KEY);
        CoroutineContext::forget(self::ATTRIBUTES_CONTEXT_KEY);
    }

    /**
     * Get the name of the session.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the name of the session.
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Get the current session ID.
     */
    public function id(): ?string
    {
        return $this->getId();
    }

    /**
     * Get the current session ID.
     */
    public function getId(): ?string
    {
        return CoroutineContext::get(self::ID_CONTEXT_KEY, null);
    }

    /**
     * Set the session ID.
     */
    public function setId(?string $id): void
    {
        CoroutineContext::set(
            self::ID_CONTEXT_KEY,
            $this->isValidId($id) ? $id : $this->generateSessionId()
        );
    }

    /**
     * Determine if this is a valid session ID.
     */
    public function isValidId(?string $id): bool
    {
        return is_string($id) && ctype_alnum($id) && strlen($id) === self::SESSION_ID_LENGTH;
    }

    /**
     * Get a new, random session ID.
     */
    protected function generateSessionId(): string
    {
        return Str::random(self::SESSION_ID_LENGTH);
    }

    /**
     * Set the existence of the session on the handler if applicable.
     */
    public function setExists(bool $value): void
    {
        if ($this->handler instanceof ExistenceAwareInterface) {
            $this->handler->setExists($value);
        }
    }

    /**
     * Get the CSRF token value.
     */
    public function token(): ?string
    {
        return $this->get('_token');
    }

    /**
     * Regenerate the CSRF token value.
     */
    public function regenerateToken(): void
    {
        $this->put('_token', Str::random(self::SESSION_ID_LENGTH));
    }

    /**
     * Determine if the previous URI is available.
     */
    public function hasPreviousUri(): bool
    {
        return ! is_null($this->previousUrl());
    }

    /**
     * Get the previous URL from the session as a URI instance.
     *
     * @throws RuntimeException
     */
    public function previousUri(): Uri
    {
        if ($previousUrl = $this->previousUrl()) {
            return Uri::of($previousUrl);
        }

        throw new RuntimeException('Unable to generate URI instance for previous URL. No previous URL detected.');
    }

    /**
     * Get the previous URL from the session.
     */
    public function previousUrl(): ?string
    {
        return $this->get('_previous.url');
    }

    /**
     * Set the "previous" URL in the session.
     */
    public function setPreviousUrl(string $url): void
    {
        $this->put('_previous.url', $url);
    }

    /**
     * Get the previous route name from the session.
     */
    public function previousRoute(): ?string
    {
        return $this->get('_previous.route');
    }

    /**
     * Set the "previous" route name in the session.
     */
    public function setPreviousRoute(?string $route): void
    {
        $this->put('_previous.route', $route);
    }

    /**
     * Specify that the user has confirmed their password.
     */
    public function passwordConfirmed(): void
    {
        $this->put('auth.password_confirmed_at', Date::now()->unix());
    }

    /**
     * Get the underlying session handler implementation.
     */
    public function getHandler(): SessionHandlerInterface
    {
        return $this->handler;
    }

    /**
     * Set the underlying session handler implementation.
     */
    public function setHandler(SessionHandlerInterface $handler): SessionHandlerInterface
    {
        return $this->handler = $handler;
    }

    /**
     * Determine if the session handler needs a request.
     */
    public function handlerNeedsRequest(): bool
    {
        return $this->handler instanceof CookieSessionHandler;
    }

    /**
     * Set the request on the handler instance.
     */
    public function setRequestOnHandler(Request $request): void
    {
        if ($this->handler instanceof CookieSessionHandler) {
            $this->handler->setRequest($request);
        }
    }
}
