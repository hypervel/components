<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\NoNativeRequestApisRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoNativeRequestApisRule> */
class NoNativeRequestApisRuleTest extends RuleTestCase
{
    private const string RESPONSE_TIP = "Set headers, cookies and the status on the response with its header(), cookie() and setStatusCode() methods, and read them from the response's headers.";

    private const string SESSION_TIP = 'Use the session store through $request->session(), session() or the Session facade.';

    private const string INPUT_TIP = 'Read input from the request with its query(), input(), post(), cookie() and file() methods.';

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoNativeRequestApisRule::class);
    }

    public function testRule(): void
    {
        $function = static fn (string $name, int $line, string $tip): array => [
            $name . "() does not act on Hypervel's request, response or session.",
            $line,
            $tip,
        ];
        $input = static fn (string $name, int $line): array => [
            '$' . $name . ' does not hold the request Hypervel is handling.',
            $line,
            self::INPUT_TIP,
        ];

        $this->analyse([__DIR__ . '/data/native-request-apis.php'], [
            $function('header', 15, self::RESPONSE_TIP),
            $function('http_response_code', 16, self::RESPONSE_TIP),
            $function('session_start', 17, self::SESSION_TIP),
            $function('connection_aborted', 21, 'Stream through a generator callback passed to response()->stream() or an IterableStreamedResponse, which stops at the first failed write after the client disconnects. Call cancelOnDisconnect() to also stop it during a wait when the connection closes.'),
            $input('_POST', 25),
            ["\$_SESSION is not Hypervel's session.", 26, self::SESSION_TIP . ' $_SESSION is shared by every request the worker process handles.'],
            $input('_GET', 28),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
