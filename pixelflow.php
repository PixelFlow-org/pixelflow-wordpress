<?php
/**
 * Plugin Name: PixelFlow
 * Description: PixelFlow Official Plugin for WordPress. Easily Install Meta's Conversions API on Your Website
 * Version: 1.1.17
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: PixelFlow Team
 * Author URI: https://pixelflow.so/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pixelflow
 * Domain Path: /languages
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PIXELFLOW_VERSION', '1.1.17');
define('PIXELFLOW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PIXELFLOW_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PIXELFLOW_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main PixelFlow class
 */
class PixelFlow
{
    /**
     * Script handle for the tracking script
     *
     * @var string
     */
    private $tracking_script_handle = 'pixelflow-tracking';

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->load_dependencies();
        add_action('plugins_loaded', 'pixelflow_register_wp_consent_api_consumer');
        add_action('init', 'pixelflow_register_consent_cookie_info');
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'migrate_product_id_format'));
        add_action('admin_init', array($this, 'handle_disable_debug_action'));
        add_action('admin_notices', array($this, 'display_debug_notice'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_print_scripts', array($this, 'inject_script'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_held_events_script'));
        add_filter('plugin_action_links_' . PIXELFLOW_PLUGIN_BASENAME, array($this, 'add_plugin_action_links'));

        // AJAX handlers
        add_action('wp_ajax_pixelflow_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_pixelflow_get_settings', array($this, 'ajax_get_settings'));
        add_action('wp_ajax_pixelflow_save_script_params', array($this, 'ajax_save_script_params'));
        add_action('wp_ajax_pixelflow_remove_script_params', array($this, 'ajax_remove_script_params'));
        add_action('wp_ajax_pixelflow_clear_debug_log', array($this, 'ajax_clear_debug_log'));
        add_action('wp_ajax_pixelflow_get_debug_log', array($this, 'ajax_get_debug_log'));
        add_action('wp_ajax_pixelflow_resolve_held_events', array($this, 'ajax_resolve_held_events'));
        add_action('wp_ajax_nopriv_pixelflow_resolve_held_events', array($this, 'ajax_resolve_held_events'));
    }

    /**
     * Load dependencies
     */
    private function load_dependencies()
    {
        // Load WooCommerce integration
        require_once PIXELFLOW_PLUGIN_PATH . 'includes/helpers.php';
        require_once PIXELFLOW_PLUGIN_PATH . 'includes/consent.php';
        require_once PIXELFLOW_PLUGIN_PATH . 'includes/blocked-events.php';
        require_once PIXELFLOW_PLUGIN_PATH . 'includes/held-events.php';
        require_once PIXELFLOW_PLUGIN_PATH . 'includes/woo/class-woocommerce-integration.php';
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
        // Initialize WooCommerce integration if enabled
        if (PixelFlow_WooCommerce_Integration::is_woocommerce_active()) {
            PixelFlow_WooCommerce_Integration::get_instance();
        }
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook)
    {
        if ($hook === 'settings_page_pixelflow-settings') {
            // Prepare settings for the admin app
            $pixelflow_general_options = get_option('pixelflow_general_options', array());
            $debug_options             = get_option('pixelflow_debug_options', array());
            $script_params             = get_option('pixelflow_script_params', '');

            $settings = array(
                'general_options'       => $pixelflow_general_options,
                'debug_options'         => $debug_options,
                'script_params'         => $script_params,
                'nonce'                 => wp_create_nonce('pixelflow_settings_nonce'),
                'ajax_url'              => admin_url('admin-ajax.php'),
                'is_woocommerce_active' => PixelFlow_WooCommerce_Integration::is_woocommerce_active(),
                'woo_debug_log_url'     => $this->get_debug_log_url(),
            );

            // Paths and versions
            $js_path  = plugin_dir_path(__FILE__) . 'app/dist/index.js';
            $css_path = plugin_dir_path(__FILE__) . 'app/dist/style.css';
            $js_url   = PIXELFLOW_PLUGIN_URL . 'app/dist/index.js';
            $css_url  = PIXELFLOW_PLUGIN_URL . 'app/dist/style.css';

            $js_version  = file_exists($js_path) ? filemtime($js_path) : PIXELFLOW_VERSION;
            $css_version = file_exists($css_path) ? filemtime($css_path) : PIXELFLOW_VERSION;

            $script_key = 'pixelflow-admin';

            // Register and enqueue style
            wp_register_style($script_key, $css_url, array(), $css_version);
            wp_enqueue_style($script_key);

            // Register and enqueue script as ES module
            wp_enqueue_script_module($script_key, $js_url, array(), $js_version);
            // Mark as module (WP >= 6.3 supports 'type' data)
            wp_script_add_data($script_key, 'type', 'module');

            // Provide settings before the script executes
            wp_register_script($script_key, '', array(), PIXELFLOW_VERSION, array('in_footer' => false));
            $inline = 'window.pixelflowSettings = ' . wp_json_encode($settings) . ';';
            wp_add_inline_script($script_key, $inline, 'before');
            wp_enqueue_script($script_key);
        }
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_options_page(
            __('PixelFlow Settings', 'pixelflow'),
            __('PixelFlow Settings', 'pixelflow'),
            'manage_options',
            'pixelflow-settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings()
    {
        register_setting('pixelflow_settings', 'pixelflow_general_options', array($this, 'sanitize_general_options'));
        register_setting('pixelflow_settings', 'pixelflow_debug_options', array($this, 'sanitize_debug_options'));
    }

    public function migrate_product_id_format()
    {
        if (get_option('pixelflow_migration_product_id_format_done')) {
            return;
        }
        $opts = get_option('pixelflow_general_options', array());
        // Only migrate already-configured installs. On a fresh install the option does not
        // exist yet, and creating it here would make the settings page treat the plugin as
        // "configured" (showing the options panel before the user has even logged in).
        if (is_array($opts) && ! empty($opts) && ! isset($opts['woo_product_id_format'])) {
            $opts['woo_product_id_format'] = ! empty($opts['woo_enabled']) ? 'legacy' : 'product_id';
            update_option('pixelflow_general_options', $opts);
        }
        update_option('pixelflow_migration_product_id_format_done', 1, true);
    }

    /**
     * Sanitize options
     */
    /**
     * Sanitize general options (enabled/disabled toggles)
     */
    public function sanitize_general_options($input)
    {
        $sanitized = array();

        // Define general checkbox options
        $checkbox_options = array(
            'enabled',
            'woo_enabled',
            'woo_purchase_tracking',
            'debug_enabled',
            'remove_on_uninstall',
            'woo_disable_add_to_cart',
            'woo_disable_initiate_checkout',
            'woo_disable_purchase',
            'woo_disable_add_to_cart_freebies',
            'woo_disable_initiate_checkout_freebies',
            'woo_disable_purchase_freebies',
            'woo_debug_enabled',
        );

        // Set all checkboxes: 1 if checked, 0 if not
        foreach ($checkbox_options as $option) {
            $sanitized[$option] = isset($input[$option]) && $input[$option] ? 1 : 0;
        }

        // Sanitize excluded_user_roles (array of role keys)
        if (isset($input['excluded_user_roles'])) {
            if ( ! is_array($input['excluded_user_roles'])) {
                $input['excluded_user_roles'] = explode(',', $input['excluded_user_roles']);
            }
            $sanitized['excluded_user_roles'] = array_map('sanitize_text_field', $input['excluded_user_roles']);
        }

        // Sanitize woo_excluded_skus (comma-separated string → filtered array of non-empty SKUs)
        if (isset($input['woo_excluded_skus'])) {
            if ( ! is_array($input['woo_excluded_skus'])) {
                $input['woo_excluded_skus'] = explode(',', $input['woo_excluded_skus']);
            }
            $sanitized['woo_excluded_skus'] = array_values(
                array_filter(array_map('sanitize_text_field', $input['woo_excluded_skus']))
            );
        }

        // Sanitize woo_product_id_format
        $allowed_formats = ['product_id', 'prefixed', 'sku', 'legacy', 'off'];
        $sanitized['woo_product_id_format'] = in_array($input['woo_product_id_format'] ?? '', $allowed_formats, true)
            ? $input['woo_product_id_format']
            : 'product_id';

        return $sanitized;
    }

    /**
     * Inject script into wp_head
     */
    public function inject_script()
    {
        if (is_admin()) {
            return;
        }
        $pixelflow_general_options = get_option('pixelflow_general_options', array());

        // Only inject if enabled and user role is not excluded
        if (isset($pixelflow_general_options['enabled']) && $pixelflow_general_options['enabled']) {
            // Check if current user's role should be excluded
            if ( ! $this->should_exclude_current_user($pixelflow_general_options)) {
                // Get saved parameters
                $params = get_option('pixelflow_script_params', array());

                // Only enqueue if params exist
                if ( ! empty($params)) {
                    // Extract parameters
                    $site_external_id = isset($params['siteExternalId']) ? $params['siteExternalId'] : '';
                    $api_key          = isset($params['apiKey']) ? $params['apiKey'] : '';

                    // Check if required params exist
                    if ( ! empty($site_external_id) && ! empty($api_key)) {
                        $script = "!(function(p,i,x,f,l,o,w){p[\"PixelFlowObject\"]=f;p[f]=p[f]||function(){(p[f].q=p[f].q||[]).push(arguments);};p[f].l=1*new Date();o=i.createElement(x);w=i.getElementsByTagName(x)[0];o.src=l;o.async=1;p[f].apiKey=\"" . esc_js(
                                $api_key
                            ) . "\";p[f].siteId=\"" . esc_js(
                                      $site_external_id
                                  ) . "\";p[f].apiEndpoint=\"https://api.pixelflow.so/event\";w.parentNode.insertBefore(o,w);})(window,document,\"script\",\"pixelFlow\",\"https://slrgkgulru.pixelflow.so/pfm.js\");";

                        $script = apply_filters('pixelflow_analytics_code', $script);

                        wp_register_script(
                            $this->tracking_script_handle,
                            '',
                            array(),
                            PIXELFLOW_VERSION,
                            array('in_footer' => false)
                        );
                        wp_add_inline_script($this->tracking_script_handle, $script, 'before');
                        wp_enqueue_script($this->tracking_script_handle);
                    }
                }
            }
        }
    }

    /**
     * Storefront script that flushes the Woo hold queue after a same-page grant or deny.
     *
     * @return void
     */
    public function enqueue_held_events_script()
    {
        if (is_admin()) {
            return;
        }

        $general = get_option('pixelflow_general_options', array());
        if (empty($general['enabled']) || empty($general['woo_enabled'])) {
            return;
        }
        if ($this->should_exclude_current_user($general)) {
            return;
        }

        $params = get_option('pixelflow_script_params', array());
        if (empty($params['siteExternalId']) || empty($params['apiKey'])) {
            return;
        }

        $handle = 'pixelflow-held-events';
        wp_register_script(
            $handle,
            PIXELFLOW_PLUGIN_URL . 'assets/js/held-events.js',
            array(),
            PIXELFLOW_VERSION,
            true
        );
        wp_localize_script(
            $handle,
            'pixelflowHeldEvents',
            array(
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('pixelflow_held_events'),
                'holdCookie'  => PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME,
                'holdValue'   => PIXELFLOW_NO_CONSENT_DECISION_COOKIE_VALUE,
                'heldCookie'  => PIXELFLOW_HELD_WOO_EVENTS_COOKIE_NAME,
            )
        );
        wp_enqueue_script($handle);
    }

    /**
     * Same-page grant or deny: flush or beacon the Woo session queue.
     *
     * @return void
     */
    public function ajax_resolve_held_events()
    {
        check_ajax_referer('pixelflow_held_events', 'nonce');
        if (class_exists('PixelFlow_WooCommerce_Cart_Hooks')) {
            $hooks = PixelFlow_WooCommerce_Cart_Hooks::instance();
            if ($hooks !== null) {
                $session = pixelflow_woo_session();
                if ($session !== null && method_exists($session, 'init_session_cookie')) {
                    $session->init_session_cookie();
                }
                $hooks->resolve_held_events();
            }
        }
        wp_send_json_success();
    }


    /**
     * Check if the current user's role is in the excluded list
     *
     * @param array $pixelflow_general_options General plugin options
     *
     * @return bool True if user should be excluded, false otherwise
     */
    private function should_exclude_current_user($pixelflow_general_options)
    {
        // If user is not logged in, never exclude (allow script injection for guests)
        if ( ! is_user_logged_in()) {
            return false;
        }

        // Get excluded user roles from settings
        $excluded_roles = isset($pixelflow_general_options['excluded_user_roles']) && is_array(
            $pixelflow_general_options['excluded_user_roles']
        )
            ? $pixelflow_general_options['excluded_user_roles']
            : array();

        // If no roles are excluded, don't exclude anyone
        if (empty($excluded_roles)) {
            return false;
        }

        // Get current user
        $current_user = wp_get_current_user();

        // Check if any of the user's roles are in the excluded list
        if ($current_user && ! empty($current_user->roles)) {
            foreach ($current_user->roles as $role) {
                if (in_array($role, $excluded_roles, true)) {
                    return true; // User has an excluded role
                }
            }
        }

        return false; // User's roles are not excluded
    }

    /**
     * Get the URL to the WooCommerce debug log file.
     * Generates a random key once and persists it as an option.
     */
    private function get_debug_log_url(): string
    {
        $key = get_option('pixelflow_debug_log_key', '');
        if (empty($key)) {
            $key = wp_generate_password(12, false);
            update_option('pixelflow_debug_log_key', $key);
        }

        $path = pixelflow_get_debug_log_path();
        if (empty($path)) {
            return '';
        }

        return content_url('/pixelflow_debug_' . preg_replace('/[^a-zA-Z0-9]/', '', $key) . '.log');
    }

    /**
     * AJAX handler to clear (delete) the WooCommerce debug log file.
     * Accepts no user-supplied parameters — the file path is derived server-side only.
     */
    public function ajax_clear_debug_log(): void
    {
        check_ajax_referer('pixelflow_settings_nonce', 'nonce');

        if ( ! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'pixelflow')), 403);
            return;
        }

        $path = pixelflow_get_debug_log_path();

        if (empty($path)) {
            wp_send_json_error(array('message' => __('Log file path could not be resolved.', 'pixelflow')), 400);
            return;
        }

        if ( ! file_exists($path)) {
            wp_send_json_success(array('message' => __('Log file does not exist.', 'pixelflow')));
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        if (unlink($path)) {
            wp_send_json_success(array('message' => __('Log file cleared.', 'pixelflow')));
        } else {
            wp_send_json_error(array('message' => __('Could not delete log file.', 'pixelflow')), 500);
        }
    }

    /**
     * AJAX handler to read and return the WooCommerce debug log file contents.
     */
    public function ajax_get_debug_log(): void
    {
        check_ajax_referer('pixelflow_settings_nonce', 'nonce');

        if ( ! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'pixelflow')), 403);
            return;
        }

        $path = pixelflow_get_debug_log_path();

        if (empty($path)) {
            wp_send_json_error(array('message' => __('Log file path could not be resolved.', 'pixelflow')), 400);
            return;
        }

        if ( ! file_exists($path)) {
            wp_send_json_success(array('content' => '', 'message' => __('Log file does not exist yet.', 'pixelflow')));
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents($path, false, null, 0, PIXELFLOW_DEBUG_LOG_MAX_SIZE);

        if ($content === false) {
            wp_send_json_error(array('message' => __('Could not read log file.', 'pixelflow')), 500);
            return;
        }

        wp_send_json_success(array('content' => $content));
    }

    /**
     * Disable Woo debug logging via a one-click admin URL action.
     */
    public function handle_disable_debug_action(): void
    {
        if ( ! isset($_GET['pixelflow_disable_debug'])) {
            return;
        }
        check_admin_referer('pixelflow_disable_debug');
        if ( ! current_user_can('manage_options')) {
            return;
        }
        $options                      = get_option('pixelflow_general_options', array());
        $options['woo_debug_enabled'] = 0;
        update_option('pixelflow_general_options', $options);
        wp_safe_redirect(remove_query_arg(array('pixelflow_disable_debug', '_wpnonce')));
        exit;
    }

    /**
     * Show an admin notice when Woo debug logging is enabled.
     */
    public function display_debug_notice(): void
    {
        if ( ! current_user_can('manage_options')) {
            return;
        }

        $options = get_option('pixelflow_general_options', array());
        if (empty($options['woo_debug_enabled'])) {
            return;
        }

        if ( ! PixelFlow_WooCommerce_Integration::is_woocommerce_active()) {
            return;
        }

        $log_path  = pixelflow_get_debug_log_path();
        $file_size = ( ! empty($log_path) && file_exists($log_path))
            ? size_format(filesize($log_path))
            : '0 B';

        $disable_url  = wp_nonce_url(
            add_query_arg('pixelflow_disable_debug', '1'),
            'pixelflow_disable_debug'
        );
        $settings_url = admin_url('options-general.php?page=pixelflow-settings');

        printf(
            '<div class="notice notice-warning"><p>'
            . '<strong>PixelFlow:</strong> '
            . 'Woo events debug logging is <strong>enabled</strong>. '
            . 'Events are being saved to a log file (current size: <strong>%s</strong>). '
            . 'This may slow down your site &mdash; disable it when not in use. '
            . '<a href="%s">Disable debug logging</a> &nbsp;|&nbsp; '
            . '<a href="%s">Go to settings</a>'
            . '</p></div>',
            esc_html($file_size),
            esc_url($disable_url),
            esc_url($settings_url)
        );
    }

    /**
     * Add plugin action links
     */
    public function add_plugin_action_links($links)
    {
        $settings_link = '<a href="' . admin_url('options-general.php?page=pixelflow-settings') . '">' . __(
                'Settings',
                'pixelflow'
            ) . '</a>';
        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Settings page callback
     */
    public function settings_page()
    {
        ?>
      <div class="wrap pixelflow-admin-wrap">
        <h1><?php
            echo esc_html(get_admin_page_title()); ?></h1>
        <p>Configure your PixelFlow integration and WooCommerce tracking settings</p>
        <div style="display: none" id="pixelflow-settings">
            <?php
            $pixelflowSiteId = apply_filters('pixelflow_site_id', "wp_" . md5(home_url()));
            global $wp_roles;

            if ( ! isset($wp_roles)) {
                $wp_roles = wp_roles();
            }
            $roles = [];
            foreach ($wp_roles->roles as $role_key => $role) {
                $roles[] = $role_key . "|" . $role["name"];
            }
            $pixelflowUserRoles = apply_filters('pixelflow_user_roles', implode(",", $roles));
            ?>
          <input id="pixelflow-site-id" value="<?php
          echo esc_html($pixelflowSiteId); ?>" type="hidden"/>
          <input id="pixelflow-user-roles" value="<?php
          echo esc_html($pixelflowUserRoles); ?>" type="hidden"/>
          <input id="pixelflow-configured" value="<?php
            $pixelflow_general_options = get_option('pixelflow_general_options', array());
            if(!empty($pixelflow_general_options)) {
              echo "1";
            } else {
              echo "0";
            }
            ?>" type="hidden"/>
        </div>
        <div id="pixelflowroot" class="pixelflow-app"></div>
      </div>
        <?php
    }

    /**
     * AJAX handler to get settings
     */
    public function ajax_get_settings()
    {
        // Verify nonce
        check_ajax_referer('pixelflow_settings_nonce', 'nonce');

        // Check user capability
        if ( ! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'pixelflow')), 403);
            return;
        }

        $pixelflow_general_options = get_option('pixelflow_general_options', array());
        $debug_options             = get_option('pixelflow_debug_options', array());
        $script_params             = get_option('pixelflow_script_params', '');

        wp_send_json_success(array(
            'general_options'       => $pixelflow_general_options,
            'debug_options'         => $debug_options,
            'script_params'         => $script_params,
            'is_woocommerce_active' => PixelFlow_WooCommerce_Integration::is_woocommerce_active(),
        ));
    }

    /**
     * AJAX handler to save settings
     */
    public function ajax_save_settings()
    {
        // Verify nonce
        check_ajax_referer('pixelflow_settings_nonce', 'nonce');

        // Check user capability
        if ( ! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'pixelflow')), 403);
            return;
        }

        // Get the posted data
        if (isset($_POST['general_options']) && is_array($_POST['general_options'])) {
            $pixelflow_general_options = array_map('sanitize_text_field', wp_unslash($_POST['general_options']));
        } else {
            $pixelflow_general_options = array();
        }

        if (isset($_POST['debug_options']) && is_array($_POST['debug_options'])) {
            $debug_options = array_map('sanitize_text_field', wp_unslash($_POST['debug_options']));
        } else {
            $debug_options = array();
        }


        // Sanitize and save options
        $sanitized_general_options = $this->sanitize_general_options($pixelflow_general_options);
        $sanitized_debug_options   = $this->sanitize_debug_options($debug_options);

        update_option('pixelflow_general_options', $sanitized_general_options);
        update_option('pixelflow_debug_options', $sanitized_debug_options);

        wp_send_json_success(array(
            'message'         => __('Settings saved successfully', 'pixelflow'),
            'general_options' => $sanitized_general_options,
            'debug_options'   => $sanitized_debug_options,
        ));
    }

    /**
     * AJAX handler to save script code and parameters
     */
    public function ajax_save_script_params()
    {
        // Verify nonce
        check_ajax_referer('pixelflow_settings_nonce', 'nonce');

        // Check user capability
        if ( ! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'pixelflow')), 403);
            return;
        }

        // Get the params (base64 encoded JSON)
        $params_encoded = '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_params = isset($_POST['params']) ? wp_unslash($_POST['params']) : '';
        if ($raw_params && preg_match('/^[A-Za-z0-9\/+=]+$/', $raw_params) === 1) {
            $params_encoded = $raw_params;
        }

        // Decode base64 params
        $params_json = base64_decode($params_encoded, true);
        if ($params_json === false) {
            wp_send_json_error(array('message' => __('Invalid base64 params payload', 'pixelflow')), 400);
            return;
        }

        // Decode JSON
        $params = json_decode($params_json, true);
        if ($params === null || json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array('message' => __('Invalid JSON payload', 'pixelflow')), 400);
            return;
        }

        // Validate required parameters
        $required_keys = array('siteExternalId', 'apiKey', 'apiEndpoint', 'cdnUrl');
        foreach ($required_keys as $key) {
            if ( ! isset($params[$key])) {
                // translators: %s is the name of the missing required parameter.
                wp_send_json_error(array('message' => sprintf(__('Missing required parameter: %s', 'pixelflow'), $key)),
                    400);
            }
        }

        // Sanitize and validate parameters
        $sanitized_params = array(
            'siteExternalId' => sanitize_text_field($params['siteExternalId']),
            'apiKey'         => sanitize_text_field($params['apiKey']),
            'apiEndpoint'    => esc_url_raw($params['apiEndpoint']),
            'cdnUrl'         => esc_url_raw($params['cdnUrl']),
        );

        // Save parameters to database option
        update_option('pixelflow_script_params', $sanitized_params);

        wp_send_json_success(array(
            'message' => __('Script parameters saved successfully', 'pixelflow'),
        ));
    }

    /**
     * AJAX handler to remove script code and parameters
     */
    public function ajax_remove_script_params()
    {
        // Verify nonce
        check_ajax_referer('pixelflow_settings_nonce', 'nonce');

        // Check user capability
        if ( ! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'pixelflow')), 403);
            return;
        }

        // Clear script parameters from options
        update_option('pixelflow_script_params', array());

        wp_send_json_success(array(
            'message' => __('Script code and parameters removed successfully', 'pixelflow'),
        ));
    }

    /**
     * Sanitize tracking URLs array
     *
     * @param array $tracking_urls Raw tracking URLs data
     *
     * @return array Sanitized tracking URLs
     */
    private function sanitize_tracking_urls(array $tracking_urls): array
    {
        $sanitized = array();
        foreach ($tracking_urls as $url_data) {
            if ( ! is_array($url_data)) {
                continue;
            }
            $sanitized[] = array(
                'url'   => esc_url_raw($url_data['url'] ?? ''),
                'event' => sanitize_text_field($url_data['event'] ?? ''),
            );
        }

        return $sanitized;
    }

    /**
     * Sanitize debug options
     *
     * @param array $input Raw input array
     * @return array Sanitized debug options
     */
    private function sanitize_debug_options(array $input): array
    {
        $sanitized = array();
        $boolean_keys = array('woo_debug_enabled');
        foreach ($boolean_keys as $key) {
            $sanitized[$key] = isset($input[$key]) ? 1 : 0;
        }
        return $sanitized;
    }


}

// Initialize the plugin
PixelFlow::get_instance();
