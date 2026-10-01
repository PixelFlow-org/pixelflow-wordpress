<?php
/**
 * Shared harness for the form-event tests (tests/test-form-*.php).
 *
 * Loads the plugin's helpers and the forms module on top of bootstrap-wp-stubs.php, with the
 * few WordPress functions they reach stubbed in memory: options, transients, the options rows
 * the repeat window claims through $wpdb, the outbound HTTP call, the current user and the post
 * store the Elementor walk reads.
 *
 * WooCommerce is deliberately absent — no WC() is defined — so every case here runs on a site
 * without it, the common case for this feature.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

if ( ! defined('PIXELFLOW_PLUGIN_BASENAME')) {
    define('PIXELFLOW_PLUGIN_BASENAME', 'pixelflow/pixelflow.php');
}
if ( ! defined('PIXELFLOW_VERSION')) {
    define('PIXELFLOW_VERSION', 'test');
}
if ( ! defined('PIXELFLOW_PLUGIN_URL')) {
    define('PIXELFLOW_PLUGIN_URL', 'https://example.test/wp-content/plugins/pixelflow/');
}
if ( ! defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-forms-test-' . getmypid());
    @mkdir(WP_CONTENT_DIR);
}

// ---------------------------------------------------------------------
// WordPress stubs
// ---------------------------------------------------------------------

function get_option($option, $default = false)
{
    return array_key_exists($option, $GLOBALS['__pf_options']) ? $GLOBALS['__pf_options'][$option] : $default;
}

function update_option($option, $value, $autoload = null)
{
    $GLOBALS['__pf_options'][$option] = $value;

    return true;
}

function get_transient($name)
{
    $row = $GLOBALS['__pf_transients'][$name] ?? null;
    if ($row === null || $row['expires'] <= $GLOBALS['__pf_now']) {
        return false;
    }

    return $row['value'];
}

function set_transient($name, $value, $ttl = 0)
{
    $GLOBALS['__pf_transients'][$name] = [
        'value'   => $value,
        'expires' => $ttl > 0 ? $GLOBALS['__pf_now'] + $ttl : PHP_INT_MAX,
    ];

    return true;
}

function delete_transient($name)
{
    unset($GLOBALS['__pf_transients'][$name]);

    return true;
}

function wp_remote_post($url, $args = [])
{
    $GLOBALS['__pf_http'][] = [
        'url'  => $url,
        'body' => json_decode((string) ($args['body'] ?? ''), true),
    ];

    return ['response' => ['code' => 202, 'message' => 'Accepted']];
}

function is_wp_error($thing): bool
{
    return false;
}

function __($text, $domain = 'default')
{
    return $text;
}

function sanitize_key($key)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function is_ssl(): bool
{
    return false;
}

function home_url($path = '')
{
    return 'https://example.test' . $path;
}

function esc_url_raw($url, $protocols = null)
{
    return (string) $url;
}

function wp_parse_url($url, $component = -1)
{
    return parse_url((string) $url, $component);
}

function is_user_logged_in(): bool
{
    return $GLOBALS['__pf_user'] !== null;
}

function wp_get_current_user()
{
    return $GLOBALS['__pf_user'];
}

function wp_doing_ajax(): bool
{
    return false;
}

function get_post($id)
{
    return $GLOBALS['__pf_posts'][(int) $id] ?? null;
}

function get_post_type($id)
{
    $post = get_post($id);

    return $post ? $post->post_type : false;
}

function get_post_meta($id, $key = '', $single = false)
{
    return $GLOBALS['__pf_post_meta'][(int) $id][$key] ?? '';
}

function get_post_types($args = [], $output = 'names')
{
    return ['post' => 'post', 'page' => 'page', 'revision' => 'revision', 'elementor_library' => 'elementor_library'];
}

/**
 * Mirrors the WP_Query rule the Elementor walk relies on: `post_status => 'any'` leaves out
 * `trash` and `auto-draft`, and only the requested post types are returned.
 */
function get_posts($args = [])
{
    $types = (array) ($args['post_type'] ?? []);
    $ids   = [];
    foreach ($GLOBALS['__pf_posts'] as $id => $post) {
        if ( ! in_array($post->post_type, $types, true)) {
            continue;
        }
        if (in_array($post->post_status, ['trash', 'auto-draft'], true)) {
            continue;
        }
        if (isset($args['meta_key']) && ! isset($GLOBALS['__pf_post_meta'][$id][$args['meta_key']])) {
            continue;
        }
        $ids[] = $id;
    }
    sort($ids);

    return ($args['fields'] ?? '') === 'ids' ? $ids : array_map('get_post', $ids);
}

/**
 * The part of $wpdb the repeat window writes: raw rows of the options table, kept apart from
 * the get_option() store as they are in WordPress. `INSERT IGNORE` answers 0 rows for a name
 * that exists, as MySQL does under the unique index on `option_name`.
 */
class PF_Test_Options_Wpdb
{
    public string $prefix  = 'wp_';
    public string $options = 'wp_options';

    public function prepare($query, ...$args)
    {
        return ['query' => $query, 'args' => $args];
    }

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    public function query($prepared)
    {
        $sql  = is_array($prepared) ? $prepared['query'] : (string) $prepared;
        $args = is_array($prepared) ? $prepared['args'] : [];

        if (strpos($sql, 'INSERT IGNORE INTO wp_options') === 0) {
            // A concurrent request whose insert landed first, invisible to any read before this one.
            if ( ! empty($GLOBALS['__pf_db_concurrent_insert'])) {
                $GLOBALS['__pf_db_concurrent_insert'] = false;
                $GLOBALS['__pf_db_options'][$args[0]] = $args[1];

                return 0;
            }
            if (array_key_exists($args[0], $GLOBALS['__pf_db_options'])) {
                return 0;
            }
            $GLOBALS['__pf_db_options'][$args[0]] = $args[1];

            return 1;
        }
        if (strpos($sql, 'DELETE FROM wp_options WHERE option_name LIKE') === 0) {
            $prefix  = stripslashes(rtrim($args[0], '%'));
            $deleted = 0;
            foreach ($GLOBALS['__pf_db_options'] as $name => $value) {
                if (strpos($name, $prefix) === 0 && (int) $value < (int) $args[1]) {
                    unset($GLOBALS['__pf_db_options'][$name]);
                    $deleted++;
                }
            }

            return $deleted;
        }

        throw new RuntimeException('Unexpected query: ' . $sql);
    }

    public function delete($table, $where)
    {
        $name = $where['option_name'] ?? null;
        if ($table !== $this->options || ! is_string($name) || ! array_key_exists($name, $GLOBALS['__pf_db_options'])) {
            return 0;
        }
        unset($GLOBALS['__pf_db_options'][$name]);

        return 1;
    }
}

$GLOBALS['wpdb']           = new PF_Test_Options_Wpdb();
$GLOBALS['__pf_db_options'] = [];

/**
 * Makes every claimed repeat window look claimed this many seconds earlier.
 *
 * @param int $seconds Age to add
 * @return void
 */
function pf_age_repeat_windows(int $seconds): void
{
    foreach ($GLOBALS['__pf_db_options'] as $name => $value) {
        if (strpos($name, 'pf_form_dedupe_') === 0) {
            $GLOBALS['__pf_db_options'][$name] = (string) ((int) $value - $seconds);
        }
    }
}

// ---------------------------------------------------------------------
// Plugin code under test
// ---------------------------------------------------------------------

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/forms/forms.php';

if (function_exists('WC')) {
    fwrite(STDERR, "WC() must not exist in the form tests\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Harness helpers
// ---------------------------------------------------------------------

/** Public client IP (TEST-NET would read as reserved on some PHP builds). */
const PF_TEST_IP = '93.184.216.34';

/**
 * Resets the request and the stores, and turns form tracking on with credentials set.
 *
 * @param array $general Overrides for pixelflow_general_options
 * @return void
 */
function pf_forms_reset(array $general = []): void
{
    $GLOBALS['__pf_options']    = [
        'pixelflow_general_options' => array_merge(['enabled' => 1, 'forms_enabled' => 1], $general),
        'pixelflow_script_params'   => ['siteExternalId' => 'wp_0123', 'apiKey' => 'key-test'],
    ];
    $GLOBALS['__pf_transients']           = [];
    $GLOBALS['__pf_db_options']           = [];
    $GLOBALS['__pf_db_concurrent_insert'] = false;
    $GLOBALS['__pf_http']                 = [];
    $GLOBALS['__pf_user']                 = null;
    $GLOBALS['__pf_now']                  = time();
    $GLOBALS['__pf_posts']                = [];
    $GLOBALS['__pf_post_meta']            = [];
    // A real form hook runs before the response is written, so the hold cookie can be set. The
    // CLI has "sent headers" as soon as a case prints PASS, so that state is emulated here; the
    // case about a response that can no longer set it switches this off.
    $GLOBALS['__pf_test_filters']         = ['pixelflow_held_form_cookie_writable' => true];
    $GLOBALS['__pf_test_filter_callbacks'] = [];

    // No visitor cookie by default: a visitor who has not answered the banner has none, and a
    // default here would let every case pass on that assumption. Cases about its presence set it.
    $_COOKIE = [];
    $_GET    = [];
    $_SERVER = [
        'REMOTE_ADDR'     => PF_TEST_IP,
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0',
        'HTTP_HOST'       => 'example.test',
        'REQUEST_URI'     => '/contact/',
    ];

    PixelFlow_Form_Dispatcher::reset_request_state();
}

/**
 * A contact form submission: native email, phone, a combined name field and a message.
 *
 * @param array $overrides Keys to replace
 * @return array
 */
function pf_contact_submission(array $overrides = []): array
{
    return array_merge([
        'source'     => 'custom',
        'form_id'    => '7',
        'form_title' => 'Contact us',
        'fields'     => [
            ['key' => 'your-name', 'type' => 'text', 'label' => 'Your name', 'value' => 'Ada Lovelace'],
            ['key' => 'your-email', 'type' => 'email', 'label' => 'Email', 'value' => ' Ada@Example.test '],
            ['key' => 'your-phone', 'type' => 'phone', 'label' => 'Phone', 'value' => '+44 (0)20 1234 5678'],
            ['key' => 'your-message', 'type' => 'textarea', 'label' => 'Message', 'value' => 'SECRET-MESSAGE write me at other@example.test'],
        ],
    ], $overrides);
}

/** Events POSTed to /event. */
function pf_sent_events(): array
{
    return array_values(array_filter($GLOBALS['__pf_http'], static function ($call) {
        return substr($call['url'], -6) === '/event';
    }));
}

/** Rows POSTed to /blocked-events. */
function pf_blocked_rows(): array
{
    $rows = [];
    foreach ($GLOBALS['__pf_http'] as $call) {
        if (substr($call['url'], -15) === '/blocked-events') {
            foreach ($call['body']['blocked'] ?? [] as $row) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

/** The only event sent, or null. */
function pf_only_event(): ?array
{
    $events = pf_sent_events();

    return count($events) === 1 ? $events[0]['body']['eventData'] : null;
}

/**
 * `_pf_consent` cookie value for a decision.
 *
 * @param string $state granted|denied
 * @return string
 */
function pf_consent_cookie(string $state): string
{
    return base64_encode((string) json_encode(['s' => $state, 't' => 1760000000, 'src' => 'cookieyes', 'v' => 1]));
}

/** Makes the request carry the visitor cookie the tracking script writes after consent. */
function pf_with_visitor_cookie(): void
{
    $_COOKIE['_pf_uid'] = 'visitor-1';
}

/** Recipes held under the hold token this browser carries. */
function pf_held_recipes(): array
{
    $token = pixelflow_held_form_token();

    return $token === null ? [] : pixelflow_get_held_form_events($token);
}

/** Makes the request carry an unanswered opt-in banner. */
function pf_banner_unanswered(): void
{
    $_COOKIE['_pf_no_consent_decision'] = 'true';
    $_COOKIE['_pf_consent_source']      = 'cookieyes';
}

/** Makes the request carry a decision and no hold. */
function pf_decide(string $state): void
{
    unset($_COOKIE['_pf_no_consent_decision']);
    $_COOKIE['_pf_consent'] = pf_consent_cookie($state);
}

/**
 * Runs one case and prints PASS/FAIL.
 *
 * @param string   $label    Case name
 * @param callable $fn       Returns true or a failure message
 * @param array    $failures Collected failures
 * @param int      $passes   Pass count
 * @return void
 */
function pf_forms_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    pf_forms_reset();

    try {
        $result = $fn();
    } catch (\Throwable $e) {
        $result = sprintf('%s: %s at %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    }

    if ($result === true) {
        $passes++;
        echo "PASS  {$label}\n";
    } else {
        $failures[] = "{$label}: {$result}";
        echo "FAIL  {$label}\n      {$result}\n";
    }
}

/**
 * Prints the summary and exits non-zero on any failure.
 *
 * @param array $failures Failures
 * @param int   $passes   Passes
 * @return void
 */
function pf_forms_finish(array $failures, int $passes): void
{
    echo "\n{$passes} passed, " . count($failures) . " failed\n";
    exit($failures === [] ? 0 : 1);
}
