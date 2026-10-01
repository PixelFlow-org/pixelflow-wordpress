<?php
/**
 * Form events: loads the module, registers the adapters' hooks, the settings routes and the
 * hold-queue routes, and enqueues the hold flush script.
 *
 * Independent of WooCommerce: it works on a site where WooCommerce is absent or its tracking
 * is off.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/catalogue.php';
require_once __DIR__ . '/submission.php';
require_once __DIR__ . '/detection.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/hold.php';
require_once __DIR__ . '/dispatcher.php';
require_once __DIR__ . '/adapters/class-form-adapter.php';
require_once __DIR__ . '/adapters/class-cf7-adapter.php';
require_once __DIR__ . '/adapters/class-wpforms-adapter.php';
require_once __DIR__ . '/adapters/class-elementor-adapter.php';
require_once __DIR__ . '/adapters/class-fluentform-adapter.php';
require_once __DIR__ . '/adapters/class-gravityforms-adapter.php';
require_once __DIR__ . '/adapters/class-ninjaforms-adapter.php';

/**
 * Every supported adapter, active or not, keyed by id.
 *
 * @return array<string, PixelFlow_Form_Adapter>
 */
function pixelflow_form_adapters(): array
{
    static $adapters = null;
    if ($adapters === null) {
        $adapters = [];
        foreach ([
            new PixelFlow_Form_Adapter_CF7(),
            new PixelFlow_Form_Adapter_Elementor(),
            new PixelFlow_Form_Adapter_FluentForm(),
            new PixelFlow_Form_Adapter_GravityForms(),
            new PixelFlow_Form_Adapter_NinjaForms(),
            new PixelFlow_Form_Adapter_WPForms(),
        ] as $adapter) {
            $adapters[$adapter->id()] = $adapter;
        }
    }

    return $adapters;
}

/**
 * Adapters whose plugin is active.
 *
 * @return array<string, PixelFlow_Form_Adapter>
 */
function pixelflow_active_form_adapters(): array
{
    return array_filter(pixelflow_form_adapters(), static function (PixelFlow_Form_Adapter $adapter) {
        return $adapter->is_active();
    });
}

/**
 * Whether a form still exists. A source no adapter knows — a form a site sends through
 * `pixelflow_track_form` — is taken to exist.
 *
 * @param string $source  Adapter id
 * @param string $form_id Plugin form id
 * @return bool
 */
function pixelflow_form_exists(string $source, string $form_id): bool
{
    $adapters = pixelflow_form_adapters();
    if ( ! isset($adapters[$source])) {
        return true;
    }

    return $adapters[$source]->is_active() && $adapters[$source]->form_exists($form_id);
}

/**
 * Whether form tracking is on: the plugin enabled and the Forms master toggle on.
 *
 * @return bool
 */
function pixelflow_forms_tracking_active(): bool
{
    $general = get_option('pixelflow_general_options', []);

    return is_array($general) && ! empty($general['enabled']) && ! empty($general['forms_enabled']);
}

/**
 * Registers everything the feature needs. Called once from the plugin bootstrap.
 *
 * @return void
 */
function pixelflow_forms_bootstrap(): void
{
    add_action('init', 'pixelflow_forms_register_adapter_hooks');
    add_action('pixelflow_track_form', 'pixelflow_forms_track_form', 10, 1);
    add_action('wp', 'pixelflow_forms_resolve_held_on_page_view', 30);
    add_action('wp_enqueue_scripts', 'pixelflow_forms_enqueue_held_script');

    add_action('wp_ajax_pixelflow_get_forms', 'pixelflow_forms_ajax_get_forms');
    add_action('wp_ajax_pixelflow_save_form_settings', 'pixelflow_forms_ajax_save');

    add_action('wp_ajax_pixelflow_held_forms_state', 'pixelflow_forms_ajax_held_state');
    add_action('wp_ajax_nopriv_pixelflow_held_forms_state', 'pixelflow_forms_ajax_held_state');
    add_action('wp_ajax_pixelflow_resolve_held_forms', 'pixelflow_forms_ajax_resolve_held');
    add_action('wp_ajax_nopriv_pixelflow_resolve_held_forms', 'pixelflow_forms_ajax_resolve_held');
}

/**
 * Hooks each active form plugin's success hook while form tracking is on.
 *
 * @return void
 */
function pixelflow_forms_register_adapter_hooks(): void
{
    if ( ! pixelflow_forms_tracking_active()) {
        return;
    }

    foreach (pixelflow_active_form_adapters() as $adapter) {
        $adapter->register_hooks();
    }
}

/**
 * `pixelflow_track_form`: lets a form plugin the plugin does not support enter the same path,
 * under the same gating.
 *
 * @param mixed $submission Normalised submission (see submission.php)
 * @return void
 */
function pixelflow_forms_track_form($submission): void
{
    PixelFlow_Form_Dispatcher::dispatch($submission, 'pixelflow_track_form');
}

/**
 * Resolves held form events on a storefront page view once the hint cookie says some exist.
 *
 * @return void
 */
function pixelflow_forms_resolve_held_on_page_view(): void
{
    if (is_admin() || wp_doing_ajax() || empty($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME])) {
        return;
    }
    if ( ! pixelflow_forms_tracking_active()) {
        return;
    }

    PixelFlow_Form_Dispatcher::resolve_held();
}

/**
 * Enqueues the form hold flush script, independent of the WooCommerce one.
 *
 * @return void
 */
function pixelflow_forms_enqueue_held_script(): void
{
    if (is_admin() || ! pixelflow_forms_tracking_active()) {
        return;
    }

    $general = get_option('pixelflow_general_options', []);
    if (pixelflow_current_user_has_excluded_role(is_array($general) ? $general : [])) {
        return;
    }

    $params = get_option('pixelflow_script_params', []);
    if (empty($params['siteExternalId']) || empty($params['apiKey'])) {
        return;
    }

    $handle = 'pixelflow-held-form-events';
    wp_register_script($handle, PIXELFLOW_PLUGIN_URL . 'assets/js/held-form-events.js', [], PIXELFLOW_VERSION, true);
    // No nonce in the page: a full-page cache may serve it long after the nonce expired. The
    // script asks the state route for a fresh one when it is about to flush.
    wp_localize_script(
        $handle,
        'pixelflowHeldFormEvents',
        [
            'stateUrl'   => add_query_arg('action', 'pixelflow_held_forms_state', admin_url('admin-ajax.php')),
            'flushUrl'   => add_query_arg('action', 'pixelflow_resolve_held_forms', admin_url('admin-ajax.php')),
            'holdCookie' => PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME,
            'holdValue'  => PIXELFLOW_NO_CONSENT_DECISION_COOKIE_VALUE,
            'heldCookie' => PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME,
        ]
    );
    wp_enqueue_script($handle);
}

/**
 * State route: whether this visitor has held form events, and a nonce minted now.
 *
 * Takes no nonce of its own — it is visitor-scoped and reveals nothing the visitor cannot
 * already infer — so it answers from a page a full-page cache served.
 *
 * @return void
 */
function pixelflow_forms_ajax_held_state(): void
{
    $token     = pixelflow_held_form_token();
    $has_queue = $token !== null && pixelflow_get_held_form_events($token) !== [];
    // A hint cookie left behind by a queue that is gone would keep the flush script polling.
    if ($token !== null && ! $has_queue) {
        pixelflow_sync_held_form_events_cookie(null);
    }

    wp_send_json_success([
        'hasQueue' => $has_queue,
        'nonce'    => wp_create_nonce('pixelflow_held_forms'),
    ]);
}

/**
 * Flush route: sends, reports or keeps this visitor's held form events.
 *
 * @return void
 */
function pixelflow_forms_ajax_resolve_held(): void
{
    check_ajax_referer('pixelflow_held_forms', 'nonce');

    if (pixelflow_forms_tracking_active()) {
        PixelFlow_Form_Dispatcher::resolve_held();
    }

    wp_send_json_success();
}

/**
 * Everything the settings page shows: the supported plugins and whether each is active, and
 * one row per form with its fields and computed state, plus a row for each configured form
 * its plugin no longer lists.
 *
 * @return array
 */
function pixelflow_forms_listing(): array
{
    $records = pixelflow_get_form_records();
    $plugins = [];
    $forms   = [];
    $listed  = [];

    foreach (pixelflow_form_adapters() as $adapter) {
        $active    = $adapter->is_active();
        $plugins[] = ['id' => $adapter->id(), 'label' => $adapter->label(), 'active' => $active];
        if ( ! $active) {
            continue;
        }

        foreach ($adapter->list_forms() as $form) {
            $key          = pixelflow_form_key($adapter->id(), $form['form_id']);
            $listed[$key] = true;
            $record       = isset($records[$key]) && is_array($records[$key]) ? $records[$key] : [];
            $fields       = array_values(array_filter(array_map(static function ($field) {
                return pixelflow_sanitize_form_field($field, false);
            }, $form['fields'])));
            $config       = pixelflow_form_effective_config($record, (string) $form['title'], $fields);

            $forms[] = [
                'key'             => $key,
                'source'          => $adapter->id(),
                'source_label'    => $adapter->label(),
                'form_id'         => $form['form_id'],
                'title'           => (string) $form['title'],
                'fields'          => $fields,
                'confidence'      => $config['confidence'],
                'suggested_event' => $config['suggested_event'],
                'detected'        => (object) $config['detected'],
                'unconfirmed'     => (object) $config['unconfirmed'],
                'record'          => (object) $record,
                'missing'         => false,
            ];
        }

        foreach ($records as $key => $record) {
            if (isset($listed[$key]) || strpos((string) $key, $adapter->id() . ':') !== 0 || ! is_array($record)) {
                continue;
            }
            $forms[] = [
                'key'             => (string) $key,
                'source'          => $adapter->id(),
                'source_label'    => $adapter->label(),
                'form_id'         => substr((string) $key, strlen($adapter->id()) + 1),
                'title'           => isset($record['title']) ? (string) $record['title'] : (string) $key,
                'fields'          => [],
                'confidence'      => 'medium',
                'suggested_event' => 'Lead',
                'detected'        => (object) [],
                'unconfirmed'     => (object) [],
                'record'          => (object) $record,
                'missing'         => true,
            ];
        }
    }

    return [
        'plugins'     => $plugins,
        'events'      => PIXELFLOW_META_STANDARD_EVENTS,
        'identifiers' => PIXELFLOW_FORM_IDENTIFIERS,
        'forms'       => $forms,
    ];
}

/**
 * Read route for the settings app.
 *
 * @return void
 */
function pixelflow_forms_ajax_get_forms(): void
{
    check_ajax_referer('pixelflow_settings_nonce', 'nonce');

    if ( ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized access', 'pixelflow')], 403);
        return;
    }

    wp_send_json_success(pixelflow_forms_listing());
}

/**
 * Save route: applies the settings page's patch, which lists only what a person changed.
 * A missing form cannot be switched on.
 *
 * @return void
 */
function pixelflow_forms_ajax_save(): void
{
    check_ajax_referer('pixelflow_settings_nonce', 'nonce');

    if ( ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized access', 'pixelflow')], 403);
        return;
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, sanitized field by field in pixelflow_apply_form_settings_patch()
    $raw   = isset($_POST['forms']) && is_string($_POST['forms']) ? wp_unslash($_POST['forms']) : '';
    $patch = json_decode($raw, true);
    if ( ! is_array($patch)) {
        wp_send_json_error(['message' => __('Invalid form settings payload', 'pixelflow')], 400);
        return;
    }

    foreach ($patch as $key => $changes) {
        if ( ! is_array($changes) || empty($changes['enabled'])) {
            continue;
        }
        $parts = explode(':', (string) $key, 2);
        if (count($parts) !== 2 || ! pixelflow_form_exists($parts[0], $parts[1])) {
            unset($patch[$key]['enabled']);
        }
    }

    update_option(
        PIXELFLOW_FORM_SETTINGS_OPTION,
        pixelflow_apply_form_settings_patch(pixelflow_get_form_records(), $patch),
        false
    );

    wp_send_json_success(pixelflow_forms_listing());
}
