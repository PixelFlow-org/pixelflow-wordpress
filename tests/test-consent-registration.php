<?php
/**
 * Consent hook registration timing.
 *
 * The registration must be attached from the plugin constructor, which runs at
 * file inclusion; attaching it from PixelFlow::init() (itself an `init`
 * callback) is too late, because `plugins_loaded` has already fired by then.
 * Loading pixelflow.php under stubs would drag in the whole admin and
 * WooCommerce surface, so the timing is asserted against the source.
 *
 * Run: php tests/test-consent-registration.php
 */

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/pixelflow.php');
if ($source === false) {
    fwrite(STDERR, "cannot read pixelflow.php\n");
    exit(1);
}

/**
 * Returns the body of a method, matched by braces from its signature.
 *
 * @param string $source    File contents
 * @param string $signature Method signature as written in the file
 * @return string|null
 */
function pf_method_body(string $source, string $signature): ?string
{
    $start = strpos($source, $signature);
    if ($start === false) {
        return null;
    }

    $open = strpos($source, '{', $start);
    if ($open === false) {
        return null;
    }

    $depth = 0;
    $length = strlen($source);
    for ($i = $open; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }
    }

    return null;
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_registration_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $result = $fn();

    if ($result === true) {
        $passes++;
        echo "PASS  {$label}\n";
    } else {
        $failures[] = "{$label}: {$result}";
        echo "FAIL  {$label}\n      {$result}\n";
    }
}

$constructor = pf_method_body($source, 'private function __construct()');
$init        = pf_method_body($source, 'public function init()');

pf_run_registration_case(
    'Consumer registration is hooked from the constructor, not from init()',
    /** @return bool|string */
    function () use ($constructor, $init) {
        if ($constructor === null || $init === null) {
            return 'could not locate __construct() or init() in pixelflow.php';
        }
        if (strpos($constructor, "add_action('plugins_loaded', 'pixelflow_register_wp_consent_api_consumer')") === false) {
            return 'constructor does not hook pixelflow_register_wp_consent_api_consumer on plugins_loaded';
        }
        if (strpos($init, 'plugins_loaded') !== false) {
            return 'init() still attaches a plugins_loaded callback, which fires too late to run';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_registration_case(
    'Cookie disclosure is hooked on init, where translations may be loaded',
    /** @return bool|string */
    function () use ($constructor) {
        if ($constructor === null) {
            return 'could not locate __construct() in pixelflow.php';
        }
        if (strpos($constructor, "add_action('init', 'pixelflow_register_consent_cookie_info')") === false) {
            return 'pixelflow_register_consent_cookie_info is not hooked on init';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_registration_case(
    'Only the cookie disclosure translates strings, and it is not on plugins_loaded',
    /** @return bool|string */
    function () {
        $consent = file_get_contents(dirname(__DIR__) . '/includes/consent.php');
        if ($consent === false) {
            return 'cannot read includes/consent.php';
        }

        $consumer = pf_method_body($consent, 'function pixelflow_register_wp_consent_api_consumer(): void');
        if ($consumer === null) {
            return 'could not locate pixelflow_register_wp_consent_api_consumer()';
        }
        if (strpos($consumer, '__(') !== false || strpos($consumer, 'wp_add_cookie_info') !== false) {
            return 'the plugins_loaded callback still loads translations, which WP 6.7+ reports as _doing_it_wrong';
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
