# Fortify OTPHP And Chillerlan Refactor Plan

## Scope

Refactor Hypervel Fortify two-factor authentication to use `spomky-labs/otphp` for TOTP and `chillerlan/php-qrcode` for QR SVG generation, while preserving Fortify's public API shape and making the final codebase read as if it was designed this way from the start.

## Goal

Build the best modern Hypervel Fortify two-factor implementation:

- Use a TOTP library with a modern object model, PSR clock support, explicit per-secret objects, and no shared mutable engine state.
- Use a QR library with stronger modern PHP architecture, better SVG rendering performance, and a cleaner path for high-volume generation.
- Remove all stale references to `pragmarx/google2fa`, `bacon/bacon-qr-code`, and the old shared-engine safety workaround.
- Fix the replay-cache TTL bug discovered during the dependency audit.
- Keep the public Fortify API familiar to Laravel users and easy to reconcile with future Laravel Fortify changes.
- Avoid compatibility shims, dead abstractions, commented-out code, unfinished placeholders, and documentation that describes the old implementation.

Churn and backwards compatibility do not constrain this plan. If the implementation exposes less-than-ideal code, fix the underlying design rather than preserving it because it already works.

## Source Material Reviewed

Project instructions:

- Monorepo root `CLAUDE.md`, in full during this Fortify dependency review session.
- Component repo `AGENTS.md`, in full on 2026-07-03 before writing this plan.

Hypervel source:

- `composer.json`
- `src/fortify/composer.json`
- `src/fortify/README.md`
- `src/fortify/src/Contracts/TwoFactorAuthenticationProvider.php`
- `src/fortify/src/FortifyServiceProvider.php`
- `src/fortify/src/TwoFactorAuthenticationProvider.php`
- `src/fortify/src/TwoFactorAuthenticatable.php`
- `src/fortify/src/Http/Controllers/TwoFactorQrCodeController.php`
- `src/boost/docs/fortify.md`
- `tests/Fortify/TwoFactorAuthenticationProviderTest.php`
- `tests/Fortify/AuthenticatedSessionControllerWithTwoFactorTest.php`
- `tests/Fortify/TwoFactorAuthenticationControllerTest.php`

Laravel reference under the monorepo root:

- `examples/laravel/fortify/src/Contracts/TwoFactorAuthenticationProvider.php`
- `examples/laravel/fortify/src/TwoFactorAuthenticationProvider.php`
- `examples/laravel/fortify/src/TwoFactorAuthenticatable.php`

Dependency sources and metadata:

- Current installed `vendor/pragmarx/google2fa`, especially `src/Support/QRCode.php`.
- Current installed `vendor/bacon/bacon-qr-code`.
- `spomky-labs/otphp`, cloned to `/tmp/otphp-hypervel-eval`.
- Installable OTPHP metadata from `composer show spomky-labs/otphp --all`.
- `chillerlan/php-qrcode`, cloned to `/tmp/php-qrcode-hypervel-eval`.
- Installable chillerlan 6 source under `/tmp/vendor/chillerlan/php-qrcode`.
- Installable chillerlan metadata from `composer show chillerlan/php-qrcode --all`.
- Filament, cloned to `/tmp/filament-hypervel-eval`, plus `pragmarx/google2fa-qrcode` cloned to `/tmp/google2fa-qrcode-hypervel-eval`.
- Codesonic consensus messages:
  - `/home/binaryfire/workspace/monorepo/.codesonic/agents/messages/2026-07-03-171840-codex-to-claude-second-opinion-hypervel-fortify-otphp-chillerlan-refactor-proposal.md`
  - `/home/binaryfire/workspace/monorepo/.codesonic/agents/messages/2026-07-03-172425-claude-to-codex-second-opinion-hypervel-fortify-otphp-chillerlan-refactor-proposal.md`
  - `/home/binaryfire/workspace/monorepo/.codesonic/agents/messages/2026-07-03-172525-codex-to-claude-second-opinion-hypervel-fortify-otphp-chillerlan-refactor-proposal.md`
  - `/home/binaryfire/workspace/monorepo/.codesonic/agents/messages/2026-07-03-172753-claude-to-codex-second-opinion-hypervel-fortify-otphp-chillerlan-refactor-proposal.md`
  - `/home/binaryfire/workspace/monorepo/.codesonic/agents/messages/2026-07-03-172818-codex-to-claude-second-opinion-hypervel-fortify-otphp-chillerlan-refactor-proposal.md`

## Current State

### TOTP

Hypervel Fortify currently has an upstream-style public contract at `src/fortify/src/Contracts/TwoFactorAuthenticationProvider.php`:

```php
interface TwoFactorAuthenticationProvider
{
    public function generateSecretKey(int $secretLength = 32): string;

    public function qrCodeUrl(string $companyName, string $companyEmail, string $secret): string;

    public function verify(string $secret, string $code): bool;
}
```

The concrete `Hypervel\Fortify\TwoFactorAuthenticationProvider` currently wraps one injected `PragmaRX\Google2FA\Google2FA` engine. Hypervel already differs from Laravel by:

- Defaulting generated secrets to `32` characters instead of Laravel's `16`.
- Using a replay cache key that includes both secret and code: `fortify.2fa_codes.` plus `hash('xxh128', $secret . '|' . $code)`.
- Passing the configured `window` to Google2FA per verification call instead of mutating the shared Google2FA engine.

The current replay TTL is wrong:

```php
$this->cache?->put($key, $timestamp, ($this->engine->getWindow($window) ?: 1) * 60);
```

For the default window of `1`, Fortify accepts the previous, current, and next 30-second TOTP steps. That acceptance span is 90 seconds, but the replay cache entry lives only 60 seconds. The correct TTL is:

```php
(2 * $window + 1) * $period
```

This is not a crypto rewrite. It is the cache retention period that keeps an already accepted code un-reusable for the full time Fortify still accepts that code.

### QR SVG

`TwoFactorAuthenticatable::twoFactorQrCodeSvg()` currently constructs Bacon directly inside the Eloquent user trait:

```php
$svg = (new Writer(
    new ImageRenderer(
        new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72))),
        new SvgImageBackEnd
    )
))->writeString($this->twoFactorQrCodeUrl());

return trim(substr($svg, strpos($svg, "\n") + 1));
```

The current behavior is:

- Raw SVG returned, not a data URI.
- XML declaration stripped.
- Fixed 192px dimensions.
- No quiet zone.
- White light modules/background.
- Dark modules rendered as RGB `45, 55, 72` (`#2d3748`).

Fortify does not currently expose a public QR customization API. `config/fortify.php` has `paths.two-factor.qr-code`, but that is a route path, not QR rendering configuration.

## Dependency Decision

### TOTP: Use `spomky-labs/otphp`

Use `spomky-labs/otphp:^11` instead of `pragmarx/google2fa`.

Reasons:

- OTPHP models each TOTP secret as its own object. That is a better natural fit for Hypervel's long-lived Swoole workers than a shared mutable helper engine.
- OTPHP supports `Psr\Clock\ClockInterface`. Deterministic verification tests can inject a fixed clock without touching global time state.
- OTPHP v11 already deprecates missing clock injection, and v12 will require a clock. Building Fortify with mandatory clock injection now avoids a known future migration.
- OTPHP keeps the security-sensitive TOTP pieces inside the library: HMAC, truncation, base32 handling, digest, digits, and period support.
- The Fortify-specific logic needed around OTPHP is not crypto code. It is Fortify policy: step-window selection, replay-cache lookup/storage, and byte-compatible otpauth URL construction.
- OTPHP's extra verification cost is irrelevant in the Fortify login path. The local microbench measured OTPHP slower than Google2FA by a few microseconds per verify; password hashing, session, database, cache, and request handling dominate this path.

Dependency graph:

- Current `pragmarx/google2fa:^9.0` requires PHP `^7.1|^8.0` and `paragonie/constant_time_encoding`.
- New `spomky-labs/otphp:^11` requires PHP `>=8.1`, `paragonie/constant_time_encoding`, `psr/clock`, and `symfony/deprecation-contracts`.
- `paragonie/constant_time_encoding`, `psr/clock`, and `symfony/deprecation-contracts` are already present elsewhere in the monorepo dependency graph, but Fortify must declare what it directly imports.

### QR: Use `chillerlan/php-qrcode`

Use `chillerlan/php-qrcode:^6.0` instead of `bacon/bacon-qr-code`.

Reasons:

- chillerlan has a modern settings/output architecture and supports SVG output directly.
- Local microbenching showed chillerlan SVG rendering materially faster than Bacon for the Fortify otpauth payload shape.
- chillerlan's `QRCode` object accumulates `$dataSegments`, so Fortify must create a fresh `QRCode` per render. That is clear and coroutine-safe.
- QR rendering does not belong inside an Eloquent authentication trait. A small concrete renderer gives Fortify a cleaner internal design without inventing a new public extension point.
- A concrete renderer keeps `TwoFactorAuthenticatable` focused on user two-factor state and keeps verbose chillerlan options out of the model trait.
- Filament's MFA stack reaches chillerlan through `pragmarx/google2fa-qrcode` service classes. The useful pattern is "QR rendering lives in a service and creates fresh QRCode instances"; Hypervel should not add that intermediate package or copy its data URI behavior.

Dependency graph:

- Current `bacon/bacon-qr-code:^3.1` requires `ext-iconv` and `dasprid/enum`.
- New `chillerlan/php-qrcode:^6.0` requires PHP `^8.2`, `ext-mbstring`, and `chillerlan/php-settings-container:^3.2.1`.
- The root monorepo already requires `ext-mbstring`.
- `chillerlan/php-qrcode` main branch currently targets newer PHP/settings-container constraints than stable 6.x. Use the stable `^6.0` constraint and verify the installed vendor source after Composer updates.

## Architecture Decisions

### Keep The Existing TOTP Provider Contract

Do not add another TOTP adapter class. The existing concrete `Hypervel\Fortify\TwoFactorAuthenticationProvider` is already the adapter behind the upstream-compatible Fortify contract.

Why:

- Laravel Fortify already has `Contracts\TwoFactorAuthenticationProvider`.
- Future Laravel Fortify merges still see the same public methods: `generateSecretKey()`, `qrCodeUrl()`, and `verify()`.
- Adding a second wrapper under the provider would create an extra layer with no additional ownership boundary.
- The Fortify-specific policy code belongs in this provider because the provider contract is exactly the boundary where Fortify turns a third-party TOTP library into Fortify behavior.

### Add A Concrete QR Renderer, Not A QR Contract

Add a concrete internal renderer, for example:

- `src/fortify/src/TwoFactorQrCodeRenderer.php`
- `src/fortify/src/TwoFactorQrCodeSvgOutput.php`

Do not add `Contracts\TwoFactorQrCodeRenderer`.

Why:

- Laravel does not have a QR renderer contract.
- Hypervel does not currently expose QR customization as a public feature.
- An interface with one internal implementation would be abstraction without a real extension point.
- A concrete renderer is still a better final codebase than keeping chillerlan's verbose option setup inside an Eloquent trait.

The renderer is stateless and may be auto-singletoned safely by Hypervel's container. It must create a fresh chillerlan `QRCode` per `svg()` call because `QRCode` stores data segments on the object.

### Keep Fixed 192px SVG Output

Keep fixed `width="192"` and `height="192"` output for Fortify's built-in 2FA QR SVG.

Why:

- The current Fortify endpoint returns an SVG sized for direct rendering in existing 2FA setup screens.
- A responsive-only SVG is more flexible for some frontends, but Fortify's existing endpoint is a ready-to-display security setup asset, not a general QR component.
- Applications can still style the SVG container or post-process output if they want a responsive presentation.
- A future general-purpose Hypervel QR package can make responsive output and broader QR customization first-class without changing Fortify's authentication trait.

This is not a Bacon compatibility concession. It is the best Fortify default because it preserves a predictable, directly embeddable QR asset for a security setup flow.

### Use A PSR Clock In Fortify Now

The provider constructor should require `Psr\Clock\ClockInterface`.

`FortifyServiceProvider` should supply `new Carbon\FactoryImmutable()` and Fortify should declare both direct dependencies:

- `psr/clock`
- `nesbot/carbon:^3.8.4`

Why:

- OTPHP v12 will require a clock.
- Tests can inject a fixed clock without global `Date::setTestNow()` state.
- `Carbon\FactoryImmutable` implements `Symfony\Component\Clock\ClockInterface`, which extends `Psr\Clock\ClockInterface`.
- Carbon is a first-class Hypervel dependency and is already directly declared by many packages that import it.
- No framework-wide `Psr\Clock\ClockInterface` binding exists in `src/` today.

Framework clock note: adding a framework-level `Psr\Clock\ClockInterface` binding would be a good foundation improvement. Fortify should not wait for it. If that binding exists by implementation time, the provider registration can use the container binding instead of `new FactoryImmutable()` with no public API change.

## Composer Plan

Update both manifests:

- Root `composer.json`, for monorepo development.
- `src/fortify/composer.json`, for subtree package distribution.

Remove:

```json
"bacon/bacon-qr-code": "^3.1",
"pragmarx/google2fa": "^9.0"
```

Add:

```json
"chillerlan/php-qrcode": "^6.0",
"nesbot/carbon": "^3.8.4",
"psr/clock": "^1.0",
"spomky-labs/otphp": "^11.0"
```

Implementation commands:

```bash
composer require chillerlan/php-qrcode:^6.0 nesbot/carbon:^3.8.4 psr/clock:^1.0 spomky-labs/otphp:^11.0
composer remove bacon/bacon-qr-code pragmarx/google2fa
```

Apply the equivalent require/remove changes to `src/fortify/composer.json` as a direct manifest edit. The root Composer commands update the monorepo lockfile; the package manifest is the subtree split contract and has no separate lockfile.

After Composer updates, inspect the actually installed vendor source before coding against it:

```bash
composer show chillerlan/php-qrcode spomky-labs/otphp bacon/bacon-qr-code pragmarx/google2fa
```

Expected stable package facts at plan time:

- `chillerlan/php-qrcode` latest stable compatible line: `6.0.1`, PHP `^8.2`, `chillerlan/php-settings-container:^3.2.1`.
- `spomky-labs/otphp` latest stable compatible line: `11.x`, PHP `>=8.1`, `paragonie/constant_time_encoding`, `psr/clock`, `symfony/deprecation-contracts`.
- `bacon/bacon-qr-code` current installed line: `v3.1.1`, requires `dasprid/enum`.
- `pragmarx/google2fa` current installed line: `v9.0.0`, requires `paragonie/constant_time_encoding`.

## TOTP Implementation Plan

### Provider Constructor

Replace the Google2FA engine with a PSR clock:

```php
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider as TwoFactorAuthenticationProviderContract;
use InvalidArgumentException;
use OTPHP\TOTP;
use OTPHP\TOTPInterface;
use Psr\Clock\ClockInterface;

class TwoFactorAuthenticationProvider implements TwoFactorAuthenticationProviderContract
{
    private const string ALGORITHM = 'SHA1';
    private const int DEFAULT_WINDOW = 1;
    private const int DIGITS = 6;
    private const int PERIOD = TOTPInterface::DEFAULT_PERIOD;

    /**
     * Create a new two factor authentication provider instance.
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ?Repository $cache = null,
    ) {
    }
}
```

Why:

- The provider owns Fortify policy.
- The clock is deterministic in tests.
- There is no shared mutable OTP engine instance in the worker.

### Service Provider Binding

Update `FortifyServiceProvider`:

```php
use Carbon\FactoryImmutable;
use Hypervel\Contracts\Cache\Repository;

$this->app->singleton(TwoFactorAuthenticationProviderContract::class, function ($app): TwoFactorAuthenticationProvider {
    return new TwoFactorAuthenticationProvider(
        new FactoryImmutable(),
        $app->make(Repository::class),
    );
});
```

Remove:

```php
use PragmaRX\Google2FA\Google2FA;
```

Why:

- `FactoryImmutable` satisfies `Psr\Clock\ClockInterface`.
- Fortify does not depend on a missing framework-wide clock binding.
- The provider remains a singleton because it holds only a clock and cache repository, not per-request state.

### Secret Generation

Keep Fortify's public contract as "base32 character length", not OTPHP's "random byte count".

```php
/**
 * Generate a new secret key.
 */
public function generateSecretKey(int $secretLength = 32): string
{
    if ($secretLength < 1) {
        throw new InvalidArgumentException('Two-factor authentication secret length must be greater than zero.');
    }

    $byteLength = (int) ceil($secretLength * 5 / 8);

    return substr(TOTP::generate($this->clock, $byteLength)->getSecret(), 0, $secretLength);
}
```

Why:

- `pragmarx/google2fa::generateSecretKey($length)` treats length as base32 characters.
- `OTPHP\TOTP::generate($clock, $secretSize)` treats size as random bytes.
- Passing `$secretLength` straight through would change `32` from 32 base32 characters to roughly 52 base32 characters.

### QR Code URL

Build the otpauth URL by hand rather than calling `TOTP::getProvisioningUri()`.

```php
/**
 * Get the two factor authentication QR code URL.
 */
public function qrCodeUrl(string $companyName, string $companyEmail, string $secret): string
{
    return 'otpauth://totp/'
        . rawurlencode($companyName)
        . ':'
        . rawurlencode($companyEmail)
        . '?secret='
        . $secret
        . '&issuer='
        . rawurlencode($companyName)
        . '&algorithm='
        . self::ALGORITHM
        . '&digits='
        . self::DIGITS
        . '&period='
        . self::PERIOD;
}
```

Why:

- Current `pragmarx/google2fa` output includes `algorithm=SHA1`, `digits=6`, and `period=30` even though they are defaults.
- OTPHP's provisioning URI generation omits default params.
- The current param order and encoding are known and easy to preserve.
- Browser and authenticator compatibility are best served by keeping the full, explicit standard parameters.

### Verification

Use OTPHP for code generation/comparison and Fortify for window/replay policy.

```php
/**
 * Verify the given code.
 */
public function verify(string $secret, string $code): bool
{
    $window = $this->window();
    $totp = TOTP::createFromSecret($secret, $this->clock);
    $key = $this->replayCacheKey($secret, $code);
    $lastAcceptedTimecode = $this->cache?->get($key);

    $matchedTimecode = $this->matchingTimecode($totp, $code, $window);

    if ($matchedTimecode === null) {
        return false;
    }

    if (is_int($lastAcceptedTimecode) && $matchedTimecode <= $lastAcceptedTimecode) {
        return false;
    }

    $this->cache?->put($key, $matchedTimecode, $this->replayTtl($window));

    return true;
}

/**
 * Find the matching TOTP timecode.
 */
private function matchingTimecode(TOTP $totp, string $code, int $window): ?int
{
    $currentTimecode = intdiv($this->clock->now()->getTimestamp(), self::PERIOD);
    $firstTimecode = max(0, $currentTimecode - $window);
    $lastTimecode = $currentTimecode + $window;

    for ($timecode = $firstTimecode; $timecode <= $lastTimecode; $timecode++) {
        if (hash_equals($totp->at($timecode * self::PERIOD), $code)) {
            return $timecode;
        }
    }

    return null;
}

/**
 * Get the configured verification window.
 */
private function window(): int
{
    $window = Features::option(Features::twoFactorAuthentication(), 'window');
    $window = is_int($window) ? $window : self::DEFAULT_WINDOW;

    if ($window < 0) {
        throw new InvalidArgumentException('Two-factor authentication window must be greater than or equal to zero.');
    }

    return $window;
}

/**
 * Get the replay cache key.
 */
private function replayCacheKey(string $secret, string $code): string
{
    return 'fortify.2fa_codes.' . hash('xxh128', $secret . '|' . $code);
}

/**
 * Get the replay cache TTL.
 */
private function replayTtl(int $window): int
{
    return (2 * $window + 1) * self::PERIOD;
}
```

Why:

- OTPHP's `verify()` leeway is seconds-based and constrained to less than one period. Fortify's option is step-based and must accept `current - window` through `current + window`.
- `TOTP::at()` takes a Unix timestamp, so the candidate loop must pass `$timecode * self::PERIOD`, not the raw timecode.
- The matched timecode lets Fortify preserve replay prevention with the existing per-`secret|code` cache key shape.
- A negative window is a configuration error and should fail clearly instead of silently rejecting every code through an empty loop.

## QR Implementation Plan

### Add `TwoFactorQrCodeRenderer`

Add a concrete renderer:

```php
namespace Hypervel\Fortify;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

class TwoFactorQrCodeRenderer
{
    private const string DARK = '#2d3748';
    private const string LIGHT = '#fff';

    /**
     * Render the QR code URL as SVG.
     */
    public function svg(string $url): string
    {
        $svg = (new QRCode(new QROptions([
            'addQuietzone' => false,
            'drawLightModules' => true,
            'eccLevel' => EccLevel::L,
            'moduleValues' => $this->moduleValues(),
            'outputBase64' => false,
            'outputInterface' => TwoFactorQrCodeSvgOutput::class,
            'svgUseFillAttributes' => true,
        ])))->render($url);

        if (! is_string($svg)) {
            throw new RuntimeException('Two-factor QR code renderer did not return SVG output.');
        }

        return trim($svg);
    }

    /**
     * Get the QR module color values.
     */
    private function moduleValues(): array
    {
        $values = [];

        foreach ($this->lightModules() as $module) {
            $values[$module] = self::LIGHT;
        }

        foreach ($this->darkModules() as $module) {
            $values[$module] = self::DARK;
        }

        return $values;
    }

    /**
     * Get the light QR module types.
     */
    private function lightModules(): array
    {
        return [
            QRMatrix::M_NULL,
            QRMatrix::M_DARKMODULE_LIGHT,
            QRMatrix::M_DATA,
            QRMatrix::M_FINDER,
            QRMatrix::M_SEPARATOR,
            QRMatrix::M_ALIGNMENT,
            QRMatrix::M_TIMING,
            QRMatrix::M_FORMAT,
            QRMatrix::M_VERSION,
            QRMatrix::M_QUIETZONE,
            QRMatrix::M_LOGO,
            QRMatrix::M_FINDER_DOT_LIGHT,
        ];
    }

    /**
     * Get the dark QR module types.
     */
    private function darkModules(): array
    {
        return [
            QRMatrix::M_DARKMODULE,
            QRMatrix::M_DATA_DARK,
            QRMatrix::M_FINDER_DARK,
            QRMatrix::M_SEPARATOR_DARK,
            QRMatrix::M_ALIGNMENT_DARK,
            QRMatrix::M_TIMING_DARK,
            QRMatrix::M_FORMAT_DARK,
            QRMatrix::M_VERSION_DARK,
            QRMatrix::M_QUIETZONE_DARK,
            QRMatrix::M_LOGO_DARK,
            QRMatrix::M_FINDER_DOT,
        ];
    }
}
```

Implementation notes:

- Keep the renderer concrete and internal.
- Do not bind it manually unless implementation-time testing shows a real container issue. As a stateless unbound concrete, Hypervel auto-singletoning is safe.
- Create a fresh `QRCode` inside `svg()` every time. Do not store a `QRCode` instance on the renderer.
- Mapping only `M_DATA` and `M_DATA_DARK` is wrong. chillerlan's output has finder, timing, alignment, format, version, quiet-zone, logo, and finder-dot module types.
- Set `svgUseFillAttributes` explicitly because the Fortify color scheme depends on inline `fill` attributes.

### Add `TwoFactorQrCodeSvgOutput`

Add a small output class to keep fixed 192px dimensions:

```php
namespace Hypervel\Fortify;

use chillerlan\QRCode\Output\QRMarkupSVG;
use function sprintf;

class TwoFactorQrCodeSvgOutput extends QRMarkupSVG
{
    /**
     * Return the SVG header.
     */
    protected function header(): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="192" height="192" viewBox="%s">%s',
            $this->getViewBox(),
            $this->options->eol,
        );
    }
}
```

Why:

- chillerlan 6's default `QRMarkupSVG::header()` emits only `viewBox` plus class/preserveAspectRatio.
- `QROptions::$scale` does not add SVG `width`/`height`.
- The output subclass never emits an XML declaration.
- A tiny output subclass is cleaner and more explicit than string-editing the generated SVG.
- Keep this class flat in `Hypervel\Fortify` alongside the renderer. The package does not currently have a `Support` subnamespace, and a one-class subdirectory would make the QR renderer split look arbitrary.

### Update `TwoFactorAuthenticatable`

Remove all Bacon imports from `TwoFactorAuthenticatable`.

Replace `twoFactorQrCodeSvg()` with:

```php
public function twoFactorQrCodeSvg(): string
{
    return Container::getInstance()
        ->make(TwoFactorQrCodeRenderer::class)
        ->svg($this->twoFactorQrCodeUrl());
}
```

Why:

- The trait should expose user two-factor behavior, not own third-party QR rendering setup.
- The public method remains unchanged.
- The renderer is stateless, so resolving it from the container is worker-safe.

### Method Docblocks

Every new or changed method must keep Hypervel's Laravel-style title docblocks, including private helpers:

- `TwoFactorAuthenticationProvider::matchingTimecode()`
- `TwoFactorAuthenticationProvider::window()`
- `TwoFactorAuthenticationProvider::replayCacheKey()`
- `TwoFactorAuthenticationProvider::replayTtl()`
- `TwoFactorQrCodeRenderer::moduleValues()`
- `TwoFactorQrCodeRenderer::lightModules()`
- `TwoFactorQrCodeRenderer::darkModules()`
- `TwoFactorQrCodeSvgOutput::header()`

Do not add explanatory comments for ordinary dependency swaps. The code and docs should describe the current design directly.

## Documentation Plan

Update `src/fortify/README.md`:

- Replace the `pragmarx/google2fa` line in `Differences From Laravel`.
- Add explicit Laravel differences for:
  - TOTP uses OTPHP with mandatory PSR clock injection and fresh per-secret objects.
  - QR SVG generation uses chillerlan through a concrete internal renderer.
  - Replay cache TTL covers the full accepted window.
- Do not mention Bacon or Google2FA as current implementation details except when describing the intentional Laravel difference.

Update `src/boost/docs/fortify.md`:

- Replace the paragraph that currently says the provider matches `pragmarx/google2fa` v9 and passes window per verification to avoid shared Google2FA mutation.
- New wording should explain:
  - Fortify defaults to 32-character TOTP secrets.
  - The `window` option is step-based and applies to previous/current/next periods according to the configured value.
  - Accepted TOTP codes are cached for the full accepted window to prevent replay.
  - Hypervel uses fresh OTPHP TOTP objects and an injected clock, so there is no shared mutable TOTP engine state in Swoole workers.

Search and remove stale references:

```bash
grep -R "pragmarx\\|Google2FA\\|bacon\\|BaconQrCode" -n src tests composer.json --exclude-dir=node_modules
```

After implementation, the only remaining references should be historical documentation in this plan and any dependency-review notes. Current source, tests, README, and Boost docs must not describe the old implementation as active.

## Testing Plan

### Provider Unit Tests

Update `tests/Fortify/TwoFactorAuthenticationProviderTest.php` to remove all Google2FA construction and mocks.

Add a tiny fixed clock helper in the test file or `tests/Fortify/Fixtures`:

```php
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

class FixedClock implements ClockInterface
{
    public function __construct(private readonly int $timestamp)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }
}
```

Required provider tests:

- `testDefaultSecretLengthIsThirtyTwoBase32Characters`
- `testCustomSecretLengthIsMeasuredInBase32Characters`
- `testRejectsInvalidSecretLength`
- `testQrCodeUrlMatchesCurrentFortifyShape`
- `testVerifiesCurrentStepCode`
- `testAcceptsCodesAtConfiguredWindowBoundaries`
- `testRejectsCodesOutsideConfiguredWindow`
- `testZeroWindowAcceptsOnlyCurrentStep`
- `testRejectsNegativeWindowConfiguration`
- `testReplayCacheKeyIncludesSecretAndCode`
- `testRejectsReplayedCodeForSameSecret`
- `testAllowsSameCodeForDifferentSecrets`
- `testReplayCacheTtlCoversFullAcceptedWindow`
- `testVerifyWorksWithoutCache`
- `testGeneratedSecretCanBeVerified`
- `testOtpLibraryMatchesRfc6238KnownAnswerVectors`

RFC 6238 vectors should use the published SHA1 8-digit examples by constructing OTPHP directly with the vector secret, timestamp, SHA1 digest, and 8 digits. This is a dependency/interoperability guard, not a substitute for the provider boundary tests. The provider boundary tests above must separately prove Fortify's candidate loop passes Unix timestamps to `TOTP::at()` by accepting and rejecting previous/current/next step codes at exact configured window boundaries.

`testGeneratedSecretCanBeVerified` should call `generateSecretKey()`, mint a current code from that generated secret with OTPHP, then assert `verify()` accepts it. This proves the generated secret format and Fortify verification path together.

The TTL assertions should cover:

- Window `0`: `30` seconds.
- Window `1`: `90` seconds.
- Window `2`: `150` seconds.

### HTTP/Controller Tests

Update valid TOTP generation in:

- `tests/Fortify/AuthenticatedSessionControllerWithTwoFactorTest.php`
- `tests/Fortify/TwoFactorAuthenticationControllerTest.php`

Use OTPHP with a real clock rather than `app(Google2FA::class)`:

```php
use Carbon\FactoryImmutable;

$code = TOTP::createFromSecret($secret, new FactoryImmutable())->now();
```

The service provider constructs the provider clock inline, so HTTP tests should not try to swap the clock through the container. Minting with a real `FactoryImmutable` clock matches the default provider clock, and the default verification window of `1` absorbs normal period-boundary crossing. Reserve `FixedClock` for direct provider unit tests that assert exact boundary behavior.

### Provider State Safety Tests

Replace `testCustomWindowDoesNotMutateSharedGoogle2faEngine` with a Hypervel-specific safety test whose name makes the intent discoverable, such as:

- `tests/Fortify/TwoFactorAuthenticationProviderCoroutineSafetyTest.php`

The test must not mutate `Features::twoFactorAuthentication()` concurrently. Fortify feature options are stored in process-global config, and mutating them inside parallel coroutines would test global config mutation rather than provider safety.

Use two focused tests instead.

First, prove the same singleton provider can verify different secrets concurrently without storing per-secret OTP state:

```php
use function Hypervel\Coroutine\parallel;

Features::twoFactorAuthentication(['window' => 1]);

[$first, $second] = parallel([
    function () use ($provider, $firstSecret, $firstCode): bool {
        usleep(5000);

        return $provider->verify($firstSecret, $firstCode);
    },
    function () use ($provider, $secondSecret, $secondCode): bool {
        usleep(5000);

        return $provider->verify($secondSecret, $secondCode);
    },
]);
```

`$provider` must be one shared provider instance, ideally the container-resolved singleton. Using two provider instances would not prove singleton safety.

Second, prove the window is resolved as Fortify policy on each verification call without a mutable TOTP engine:

```php
$provider = new TwoFactorAuthenticationProvider(new FixedClock($timestamp), $cache);

Features::twoFactorAuthentication(['window' => 0]);
$this->assertFalse($provider->verify($secret, $previousStepCode));

Features::twoFactorAuthentication(['window' => 1]);
$this->assertTrue($provider->verify($secret, $previousStepCode));
```

This replaces the old Google2FA mutation regression with tests that match the new design: fresh OTPHP objects per verification, no stored per-secret engine, and Fortify policy read at verification time. The direct provider construction with `FixedClock` makes the previous-step code deterministic.

### QR Renderer Tests

Add focused tests for `TwoFactorQrCodeRenderer`:

- `testReturnsRawSvgWithoutXmlDeclaration`
- `testSvgHasFixedWidthAndHeight`
- `testSvgIsWellFormedXml`
- `testSvgViewBoxHasNoQuietZonePadding`
- `testRenderedDarkModulesUseFortifyDarkColor`
- `testSvgDoesNotContainDefaultBlack`
- `testRenderingTwiceDoesNotAccumulateSegments`

Implementation details:

- Parse the returned SVG with `DOMDocument` or `SimpleXMLElement`.
- Assert the first non-whitespace bytes are `<svg`, not `<?xml`.
- Assert `width="192"` and `height="192"`.
- Assert no `#000` or default black appears.
- Assert every rendered dark path that is present in the SVG uses `#2d3748`. Do not require every enumerated dark `QRMatrix::M_*` type to appear in one QR code, because version and alignment module types depend on the QR version selected for the payload.
- Assert there is no quiet-zone padding by checking the SVG `viewBox` dimension against the raw QR matrix module count for the same URL with `addQuietzone => false`. A quiet zone would add 8 modules to the viewBox width and height.
- Render the same URL twice and assert identical output. This protects against accidentally storing chillerlan's mutable `QRCode` object on the renderer.
- Render two different URLs and assert each output is valid. This complements the identical-output test.

### Documentation Tests / Searches

Run stale-reference searches after implementation:

```bash
grep -R "PragmaRX\\|Google2FA\\|BaconQrCode\\|bacon/bacon-qr-code\\|pragmarx/google2fa" -n src tests composer.json --exclude-dir=node_modules
```

Expected result: no active source, tests, package metadata, README, or Boost docs refer to the removed implementation as current behavior.

## Verification Commands

Run targeted tests immediately after each affected test file is updated:

```bash
./vendor/bin/phpunit --no-progress tests/Fortify/TwoFactorAuthenticationProviderTest.php
./vendor/bin/phpunit --no-progress tests/Fortify/TwoFactorAuthenticationProviderCoroutineSafetyTest.php
./vendor/bin/phpunit --no-progress tests/Fortify/AuthenticatedSessionControllerWithTwoFactorTest.php
./vendor/bin/phpunit --no-progress tests/Fortify/TwoFactorAuthenticationControllerTest.php
```

Run static analysis and the full suite after implementation:

```bash
composer analyse
composer test:parallel
```

If package scripts require the repo's consolidated fixer/test command before final review, also run:

```bash
composer fix
```

Do not weaken tests to fit source behavior. If a test exposes a real source bug or unclear architectural problem, fix the source or stop and report the root cause with the recommended correction.

## Implementation Checklist

1. Update root and Fortify Composer manifests and lockfile with OTPHP/chillerlan/clock dependencies, removing Google2FA/Bacon.
2. Inspect installed OTPHP and chillerlan vendor sources after Composer resolves.
3. Refactor `TwoFactorAuthenticationProvider` to use `ClockInterface` and OTPHP.
4. Update `FortifyServiceProvider` to construct the provider with `Carbon\FactoryImmutable`.
5. Add `TwoFactorQrCodeRenderer`.
6. Add `TwoFactorQrCodeSvgOutput`.
7. Refactor `TwoFactorAuthenticatable` to delegate QR SVG generation to the renderer.
8. Confirm every new or changed method has a Laravel-style title docblock.
9. Update Fortify tests that mint TOTP codes.
10. Add provider replay/window/RFC tests.
11. Add QR renderer tests.
12. Update README and Boost docs.
13. Run stale-reference searches and remove every stale active reference.
14. Run targeted tests after each updated test file.
15. Run `composer analyse` and the full parallel suite.

## Re-Review Checklist

Before implementation begins, re-check these facts against the working tree and installed dependencies:

- `src/fortify/src/Contracts/TwoFactorAuthenticationProvider.php` still exposes only the three public methods planned here.
- `src/fortify/src/FortifyServiceProvider.php` still owns the provider binding.
- `src/fortify/src/TwoFactorAuthenticatable.php` still owns `twoFactorQrCodeSvg()`.
- chillerlan stable 6 still has `outputBase64`, `svgUseFillAttributes`, `addQuietzone`, `drawLightModules`, `moduleValues`, and an overridable `QRMarkupSVG::header()`.
- chillerlan stable 6 `QRCode` still stores `$dataSegments` on the instance.
- OTPHP stable 11 still has `TOTP::generate()`, `TOTP::createFromSecret()`, `TOTP::at()`, and mandatory-in-v12 clock deprecation behavior.
- `Carbon\FactoryImmutable` still satisfies `Psr\Clock\ClockInterface`.
- No framework-wide `Psr\Clock\ClockInterface` binding has been added since this plan. If one has been added, use it in the provider registration instead of constructing `FactoryImmutable` directly.
- No active source/test/doc reference still claims Fortify uses Google2FA or Bacon.

## Final Codebase Standard

After implementation, the Fortify package should read as if this was the original design:

- No Google2FA imports, service resolution, tests, or docs remain.
- No Bacon imports, renderer setup, tests, or docs remain.
- No compatibility wrappers exist solely to mimic removed dependencies.
- No QR renderer interface exists without a real public extension point.
- No old replay TTL logic remains.
- No comments explain that the code used to use another library.
- Documentation describes current Hypervel behavior directly, not as a migration story.
