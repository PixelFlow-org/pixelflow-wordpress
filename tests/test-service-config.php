<?php
/**
 * The service configuration a build writes to includes/config.php: the reader, and the
 * storefront snippet and form sends that take their addresses from it. Runs with no WC().
 *
 * A plugin with no configuration is covered in test-service-config-absent.php, and the
 * WooCommerce sends in test-configuration-gate.php.
 *
 * Run: php tests/test-service-config.php
 */

declare(strict_types=1);

define('PIXELFLOW_CONFIG_FILE', __DIR__ . '/fixtures/config.php');

require_once __DIR__ . '/bootstrap-forms.php';

function esc_js($text)
{
    return htmlspecialchars((string) $text, ENT_COMPAT);
}

$failures = [];
$passes   = 0;

/**
 * Writes a temporary configuration file and returns its path.
 *
 * @param string $body PHP after the opening tag
 * @return string
 */
function pf_config_file(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'pf-config-');
    file_put_contents($path, "<?php\n" . $body);

    return $path;
}

$fixture = [
    'api_url' => 'https://api.example.test',
    'ui_url'  => 'https://dashboard.example.test',
    'cdn_url' => 'https://cdn.example.test/pfm.js',
];

// ---------------------------------------------------------------------
// The reader
// ---------------------------------------------------------------------

pf_forms_case('The reader returns the three addresses of the file', function () use ($fixture) {
    $config = pixelflow_read_config_file(__DIR__ . '/fixtures/config.php');

    return $config === $fixture ? true : json_encode($config);
}, $failures, $passes);

pf_forms_case('The reader returns nothing for an absent file', function () {
    $config = pixelflow_read_config_file(__DIR__ . '/fixtures/no-such-config.php');

    return $config === [] ? true : json_encode($config);
}, $failures, $passes);

pf_forms_case('The reader returns nothing for a file that does not return an array', function () {
    $path   = pf_config_file("return 'https://api.example.test';\n");
    $config = pixelflow_read_config_file($path);
    unlink($path);

    return $config === [] ? true : json_encode($config);
}, $failures, $passes);

pf_forms_case('The reader drops a trailing slash and leaves a missing key empty', function () {
    $path   = pf_config_file("return array('api_url' => ' https://api.example.test/ ');\n");
    $config = pixelflow_read_config_file($path);
    unlink($path);

    return $config === ['api_url' => 'https://api.example.test', 'ui_url' => '', 'cdn_url' => '']
        ? true
        : json_encode($config);
}, $failures, $passes);

pf_forms_case('pixelflow_config() reads the file PIXELFLOW_CONFIG_FILE names', function () use ($fixture) {
    return pixelflow_config() === $fixture ? true : json_encode(pixelflow_config());
}, $failures, $passes);

// ---------------------------------------------------------------------
// The storefront snippet
// ---------------------------------------------------------------------

pf_forms_case('The snippet loads the configured script and sends to the configured API', function () use ($fixture) {
    $script = pixelflow_tracking_snippet('wp_0123', 'key-test', $fixture);

    if (strpos($script, 'p[f].apiEndpoint="https://api.example.test/event"') === false) {
        return 'apiEndpoint is not the configured one: ' . $script;
    }
    if (strpos($script, '"pixelFlow","https://cdn.example.test/pfm.js")') === false) {
        return 'the script address is not the configured one: ' . $script;
    }

    return true;
}, $failures, $passes);

pf_forms_case('No snippet without the API origin or the script address', function () use ($fixture) {
    foreach (['api_url', 'cdn_url'] as $key) {
        $config       = $fixture;
        $config[$key] = '';
        if (pixelflow_tracking_snippet('wp_0123', 'key-test', $config) !== '') {
            return "a snippet was built with an empty {$key}";
        }
    }

    return pixelflow_tracking_snippet('wp_0123', 'key-test', []) === '' ? true : 'a snippet was built with no configuration';
}, $failures, $passes);

pf_forms_case('No snippet without a credential', function () use ($fixture) {
    return pixelflow_tracking_snippet('', 'key-test', $fixture) === '' && pixelflow_tracking_snippet('wp_0123', '', $fixture) === ''
        ? true
        : 'a snippet was built with a missing credential';
}, $failures, $passes);

// ---------------------------------------------------------------------
// Form sends
// ---------------------------------------------------------------------

pf_forms_case('A form event goes to the configured API origin', function () {
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $urls = array_column($GLOBALS['__pf_http'], 'url');

    return $urls === ['https://api.example.test/event'] ? true : json_encode($urls);
}, $failures, $passes);

pf_forms_case('A form blocked report goes to the configured API origin', function () {
    $_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.31';
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $urls = array_column($GLOBALS['__pf_http'], 'url');

    return $urls === ['https://api.example.test/blocked-events'] ? true : json_encode($urls);
}, $failures, $passes);

pf_forms_finish($failures, $passes);
