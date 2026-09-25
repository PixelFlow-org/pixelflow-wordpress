<?php
/**
 * The credential gate, the notice that explains it, and the retired cookie names.
 *
 * Without credentials the API rejects every event, so nothing is sent — but the hooks still
 * register. Most of them only record: the attribution snapshot and the consent decision exist
 * only during the buyer's own request, and a site whose key is briefly empty would otherwise lose
 * them for every order created in that window, with no way to recover them afterwards. The gate
 * therefore sits at the two outbound requests, /event and /blocked-events.
 *
 * A gated site is silent, which is why the notice ships with the gate: silence the owner cannot
 * see is indistinguishable from a broken plugin.
 *
 * Run: php tests/test-configuration-gate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-config-gate-test');
}
if ( ! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}
if ( ! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

function plugin_dir_url($file)
{
    return 'https://example.test/wp-content/plugins/pixelflow/';
}

function plugin_dir_path($file)
{
    return dirname($file) . '/';
}

function plugin_basename($file)
{
    return 'pixelflow/' . basename($file);
}

$GLOBALS['__pf_test_options'] = [];
$GLOBALS['__pf_test_posts']   = [];
$GLOBALS['__pf_test_can']     = true;

function get_option($option, $default = false)
{
    return $GLOBALS['__pf_test_options'][$option] ?? $default;
}

function add_option($option, $value = '', $deprecated = '', $autoload = 'yes')
{
    $GLOBALS['__pf_test_options'][$option] = $value;

    return true;
}

function delete_option($option)
{
    unset($GLOBALS['__pf_test_options'][$option]);

    return true;
}

function current_user_can($capability)
{
    return (bool) $GLOBALS['__pf_test_can'];
}

function admin_url($path = '')
{
    return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
}

function esc_url($url)
{
    return $url;
}

function esc_html($text)
{
    return $text;
}

function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    return true;
}

function wp_next_scheduled($hook, $args = [])
{
    return false;
}

function wp_clear_scheduled_hook($hook, $args = [])
{
}

function is_wp_error($thing): bool
{
    return false;
}

function __($text, $domain = 'default')
{
    return $text;
}

function wp_remote_post($url, $args = [])
{
    $GLOBALS['__pf_test_posts'][] = [
        'url'     => $url,
        'payload' => json_decode((string) ($args['body'] ?? '{}'), true),
    ];

    return ['response' => ['code' => 200]];
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? ($response['response']['code'] ?? '') : '';
}

function wp_remote_retrieve_response_message($response)
{
    return is_array($response) ? ($response['response']['message'] ?? '') : '';
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) ? ($response['body'] ?? '') : '';
}

/** WooCommerce present, so only the credentials decide. */
class WooCommerce
{
}

class WC_Order
{
    /** @var array<string, string> */
    public $meta = [];

    public function get_id(): int
    {
        return 1;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data($key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data($key): void
    {
        unset($this->meta[$key]);
    }

    public function save(): void
    {
    }
}

// Loading the plugin file defines its constants and, through get_instance(), requires every
// include and registers its hooks against the stubs above.
require_once dirname(__DIR__) . '/pixelflow.php';
require_once PIXELFLOW_PLUGIN_PATH . 'includes/woo/hooks/class-woocommerce-hooks.php';

const PF_SITE = 'wp_0123456789abcdef0123456789abcdef';

/**
 * Runs the integration's private load_hooks() and reports whether it registered the hooks.
 *
 * @return bool
 */
function pf_hooks_were_loaded(): bool
{
    $integration = (new ReflectionClass('PixelFlow_WooCommerce_Integration'))
        ->newInstanceWithoutConstructor();

    $method = new ReflectionMethod('PixelFlow_WooCommerce_Integration', 'load_hooks');
    $method->setAccessible(true);
    $method->invoke($integration);

    return PixelFlow_WooCommerce_Cart_Hooks::instance() !== null;
}

/**
 * Runs one AddToCart through the loaded hooks instance: once as an event that would be sent,
 * once as a skip that would be beaconed to /blocked-events.
 *
 * @return void
 */
function pf_drive_one_event(): void
{
    $hooks  = PixelFlow_WooCommerce_Cart_Hooks::instance();
    $method = new ReflectionMethod('PixelFlow_WooCommerce_Cart_Hooks', 'post_event');
    $method->setAccessible(true);

    $payload = [
        'siteId'    => PF_SITE,
        'eventData' => ['eventName' => 'AddToCart', 'eventTime' => 1757000000],
    ];

    $method->invoke($hooks, $payload, []);
    $method->invoke($hooks, $payload, ['bot_rule' => 'no_cookies_in_wp_plugin']);
}

/** Resets the hooks singleton so each case observes its own run. */
function pf_reset_hooks_instance(): void
{
    if ( ! class_exists('PixelFlow_WooCommerce_Cart_Hooks')) {
        return;
    }
    $prop = new ReflectionProperty('PixelFlow_WooCommerce_Cart_Hooks', 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

/**
 * Renders the unconfigured notice and returns its markup.
 *
 * @return string
 */
function pf_render_unconfigured_notice(bool $enabled = true): string
{
    $GLOBALS['__pf_test_filters']['pixelflow_show_unconfigured_notice'] = $enabled;
    ob_start();
    PixelFlow::get_instance()->display_unconfigured_notice();
    unset($GLOBALS['__pf_test_filters']['pixelflow_show_unconfigured_notice']);

    return (string) ob_get_clean();
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_gate_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE                      = [];
    $GLOBALS['__pf_test_posts']   = [];
    $GLOBALS['__pf_test_options'] = [];
    $GLOBALS['__pf_test_can']     = true;
    pf_reset_hooks_instance();

    try {
        $result = $fn();
    } catch (\Throwable $e) {
        $result = sprintf('%s: %s', get_class($e), $e->getMessage());
    }

    if ($result === true) {
        $passes++;
        echo "PASS  {$label}\n";
    } else {
        $failures[] = "{$label}: {$result}";
        echo "FAIL  {$label}\n      {$result}\n";
    }
}

// ---------------------------------------------------------------------
// The gate.
// ---------------------------------------------------------------------

pf_run_gate_case(
    'Both credentials present: the WooCommerce hooks are registered',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_options']['pixelflow_script_params'] = [
            'siteExternalId' => PF_SITE,
            'apiKey'         => 'k',
        ];

        return pf_hooks_were_loaded() ? true : 'the hooks were not registered';
    },
    $failures,
    $passes
);

pf_run_gate_case(
    'Both credentials present: the event and the beacon reach the API',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_options']['pixelflow_script_params'] = [
            'siteExternalId' => PF_SITE,
            'apiKey'         => 'k',
        ];
        pf_hooks_were_loaded();
        pf_drive_one_event();

        $urls = array_column($GLOBALS['__pf_test_posts'], 'url');
        foreach (['/event', '/blocked-events'] as $path) {
            if ( ! in_array('https://api.pixelflow.so' . $path, $urls, true)) {
                return "nothing reached {$path}: " . json_encode($urls);
            }
        }

        return true;
    },
    $failures,
    $passes
);

foreach (
    [
        'the API key is empty'         => ['siteExternalId' => PF_SITE, 'apiKey' => ''],
        'the site identifier is empty' => ['siteExternalId' => '', 'apiKey' => 'k'],
        'both are empty'               => ['siteExternalId' => '', 'apiKey' => ''],
    ] as $label => $params
) {
    pf_run_gate_case(
        "The recording hooks still register when {$label}",
        /** @return bool|string */
        function () use ($params) {
            // The attribution snapshot, the consent decision carried onto open orders and the
            // held-event flush all live on these hooks, and the data behind them exists only
            // during the buyer's own request.
            $GLOBALS['__pf_test_options']['pixelflow_script_params'] = $params;

            return pf_hooks_were_loaded() ? true : 'the hooks were not registered';
        },
        $failures,
        $passes
    );

    pf_run_gate_case(
        "No request leaves the site when {$label}",
        /** @return bool|string */
        function () use ($params) {
            $GLOBALS['__pf_test_options']['pixelflow_script_params'] = $params;
            if ( ! pf_hooks_were_loaded()) {
                return 'the hooks were not registered';
            }

            pf_drive_one_event();

            return $GLOBALS['__pf_test_posts'] === []
                ? true
                : 'a request left the site: ' . json_encode(array_column($GLOBALS['__pf_test_posts'], 'url'));
        },
        $failures,
        $passes
    );
}

// ---------------------------------------------------------------------
// The notice that explains the gate.
// ---------------------------------------------------------------------

pf_run_gate_case(
    'The notice is off by default, even with a credential empty',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_options']['pixelflow_script_params'] = [
            'siteExternalId' => PF_SITE,
            'apiKey'         => '',
        ];

        return pf_render_unconfigured_notice(false) === ''
            ? true
            : 'the notice rendered without pixelflow_show_unconfigured_notice opting in';
    },
    $failures,
    $passes
);

pf_run_gate_case(
    'The notice renders when a credential is empty, and links to the settings screen',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_options']['pixelflow_script_params'] = [
            'siteExternalId' => PF_SITE,
            'apiKey'         => '',
        ];

        $markup = pf_render_unconfigured_notice();
        if (strpos($markup, 'notice-error') === false) {
            return 'no error notice was rendered: ' . var_export($markup, true);
        }
        if (strpos($markup, 'no events are being sent') === false) {
            return 'the notice does not say events are not being sent';
        }
        if (strpos($markup, 'page=pixelflow-settings') === false) {
            return 'the notice does not link to the settings screen';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_gate_case(
    'The notice stays away when both settings are set',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_options']['pixelflow_script_params'] = [
            'siteExternalId' => PF_SITE,
            'apiKey'         => 'k',
        ];

        return pf_render_unconfigured_notice() === '' ? true : 'a notice was rendered anyway';
    },
    $failures,
    $passes
);

pf_run_gate_case(
    'The credentials are the only condition: the toggles do not silence the notice',
    /** @return bool|string */
    function () {
        // An empty credential is a misconfiguration rather than a choice, and the browser script
        // is gated on the same two values, so the notice is deliberately independent of these.
        $GLOBALS['__pf_test_options']['pixelflow_script_params']    = [
            'siteExternalId' => '',
            'apiKey'         => '',
        ];
        $GLOBALS['__pf_test_options']['pixelflow_general_options'] = [
            'enabled'     => 0,
            'woo_enabled' => 0,
        ];

        return strpos(pf_render_unconfigured_notice(), 'notice-error') !== false
            ? true
            : 'the notice was suppressed by the enable toggles';
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Retired cookie names.
// ---------------------------------------------------------------------

pf_run_gate_case(
    'A stale retired cookie reaches neither the payload nor the log key list',
    /** @return bool|string */
    function () {
        $_COOKIE['pf_clkid'] = 'stale-click-id';
        $_COOKIE['pf_fbc']   = 'stale-fbc';
        $_COOKIE['_fbc']     = 'fb.1.1757000000.current';

        $payload = ['eventData' => ['eventName' => 'AddToCart']];
        pixelflow_append_cookie_params($payload);

        $encoded = (string) wp_json_encode($payload);
        if (strpos($encoded, 'stale-click-id') !== false || strpos($encoded, 'stale-fbc') !== false) {
            return 'a retired cookie value reached the payload: ' . $encoded;
        }
        if (($payload['eventData']['fbc'] ?? null) !== 'fb.1.1757000000.current') {
            return '_fbc is no longer read directly: ' . $encoded;
        }
        if (array_key_exists('clkId', $payload['eventData'])) {
            return 'the retired clkId field is still emitted';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_gate_case(
    'Live cookies copy ttp and ttclid only',
    /** @return bool|string */
    function () {
        $_COOKIE['_ttp']          = 'tiktok-browser-1';
        $_COOKIE['_pf_click_ids'] = 'ttclid=E_C_P_abc&gclid=other';

        $payload = ['eventData' => ['eventName' => 'AddToCart']];
        pixelflow_append_cookie_params($payload);

        $event = $payload['eventData'];
        if (($event['ttp'] ?? null) !== 'tiktok-browser-1') {
            return 'ttp did not reach the payload: ' . wp_json_encode($event);
        }
        if (($event['ttclid'] ?? null) !== 'E_C_P_abc') {
            return 'ttclid did not reach the payload: ' . wp_json_encode($event);
        }
        if (array_key_exists('gclid', $event)) {
            return 'gclid was forwarded from the click-id bag';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_gate_case(
    'A missing TikTok cookie omits the field',
    /** @return bool|string */
    function () {
        $payload = ['eventData' => ['eventName' => 'AddToCart']];
        pixelflow_append_cookie_params($payload);

        $event = $payload['eventData'];
        if (array_key_exists('ttp', $event) || array_key_exists('ttclid', $event)) {
            return 'an absent cookie still emitted a TikTok field: ' . wp_json_encode($event);
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
