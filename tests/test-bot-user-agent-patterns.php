<?php
/**
 * Tests for pixelflow_if_is_bot() user-agent classification.
 *
 * Covers the "Automation user agents are recognised as bots" and "Meta
 * crawler variants are recognised as bots" requirements of the
 * event-noise-filtering capability.
 *
 * The concrete agents asserted here were taken from a live AddToCart
 * export (poolbarlondon.com, 175 events over 13-18 Aug 2026) in which 33
 * events reached the API because these agents were not matched.
 *
 * No PHPUnit/composer is set up in this repo, so this is a plain, minimal
 * `php`-runnable script. Run with:
 *
 *   php tests/test-bot-user-agent-patterns.php
 *
 * Exit code 0 = all assertions passed.
 * Exit code 1 = at least one assertion failed.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$failures = [];
$passes   = 0;

function pf_assert_bot(string $ua, bool $expected, array &$failures, int &$passes): void
{
    $actual = pixelflow_if_is_bot($ua);
    $label  = sprintf('%s => %s', $ua, $expected ? 'bot' : 'not a bot');

    if ($actual === $expected) {
        $passes++;
        echo "PASS  {$label}\n";
        return;
    }

    $message = sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true));
    $failures[] = "{$label}: {$message}";
    echo "FAIL  {$label}\n      {$message}\n";
}

// ---------------------------------------------------------------------
// Automation agents observed in the field export.
// ---------------------------------------------------------------------
pf_assert_bot('Python/3.14 aiohttp/3.14.1', true, $failures, $passes);
pf_assert_bot('python-httpx/0.28.1', true, $failures, $passes);
pf_assert_bot('meta-externalads/1.1', true, $failures, $passes);

// Previously covered agents must keep matching.
pf_assert_bot('meta-externalagent/1.1', true, $failures, $passes);
pf_assert_bot('facebookexternalhit/1.1', true, $failures, $passes);
pf_assert_bot('python-requests/2.31.0', true, $failures, $passes);
pf_assert_bot('curl/8.5.0', true, $failures, $passes);
pf_assert_bot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true, $failures, $passes);

// Other generic HTTP client libraries in the same category.
pf_assert_bot('okhttp/4.12.0', true, $failures, $passes);
pf_assert_bot('Go-http-client/2.0', true, $failures, $passes);
pf_assert_bot('Java/17.0.9', true, $failures, $passes);
pf_assert_bot('libwww-perl/6.72', true, $failures, $passes);
pf_assert_bot('GuzzleHttp/7', true, $failures, $passes);
pf_assert_bot('node-fetch/1.0', true, $failures, $passes);

// ---------------------------------------------------------------------
// Genuine browsers from the same export must NOT be filtered.
// ---------------------------------------------------------------------
pf_assert_bot(
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
    false,
    $failures,
    $passes
);
pf_assert_bot(
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
    false,
    $failures,
    $passes
);
pf_assert_bot(
    'Mozilla/5.0 (iPhone; CPU iPhone OS 26_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/23G71 Instagram 442.0.0.22.64',
    false,
    $failures,
    $passes
);

// A browser UA containing "meta" must not match on the Meta signature.
pf_assert_bot('Mozilla/5.0 MetaBrowser/2.0', false, $failures, $passes);

// Empty / absent user agent is not, by itself, a bot signature.
pf_assert_bot('', false, $failures, $passes);

// ---------------------------------------------------------------------
// Matching stays case-insensitive across the added signatures.
// ---------------------------------------------------------------------
pf_assert_bot('PYTHON/3.14 AIOHTTP/3.14.1', true, $failures, $passes);
pf_assert_bot('Meta-ExternalAds/1.1', true, $failures, $passes);

// ---------------------------------------------------------------------
// The signature set stays extensible by site owners through the
// `pixelflow_useragent_bot_patterns` filter: a site can both add a
// signature and remove one that misfires on its traffic.
// ---------------------------------------------------------------------
$pf_add_pattern = function (array $patterns): array {
    $patterns[] = 'acme-internal-scanner';
    return $patterns;
};
add_filter('pixelflow_useragent_bot_patterns', $pf_add_pattern);

pf_assert_bot('acme-internal-scanner/3.0', true, $failures, $passes);

remove_filter('pixelflow_useragent_bot_patterns', $pf_add_pattern);
pf_assert_bot('acme-internal-scanner/3.0', false, $failures, $passes);

// Removing a shipped pattern makes a previously matching agent non-bot.
$pf_drop_guzzle = function (array $patterns): array {
    return array_values(array_filter($patterns, function ($p) {
        return $p !== 'guzzle';
    }));
};
add_filter('pixelflow_useragent_bot_patterns', $pf_drop_guzzle);

pf_assert_bot('GuzzleHttp/7', false, $failures, $passes);
// Unrelated signatures are unaffected by the removal.
pf_assert_bot('python-httpx/0.28.1', true, $failures, $passes);

remove_filter('pixelflow_useragent_bot_patterns', $pf_drop_guzzle);
pf_assert_bot('GuzzleHttp/7', true, $failures, $passes);

echo "\n" . count($failures) . ' failing / ' . $passes . ' passing (of ' . ($passes + count($failures)) . " cases)\n";

if ( ! empty($failures)) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}

exit(0);
