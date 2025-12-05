<?php
/**
 * Plugin Name: PixelFlow
 * Description: PixelFlow Official Plugin for WordPress. Easily Install Meta's Conversions API on Your Website
 * Version: 0.1.21
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
define('PIXELFLOW_VERSION', '0.1.21');
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
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_print_scripts', array($this, 'inject_script'));
        add_filter('plugin_action_links_' . PIXELFLOW_PLUGIN_BASENAME, array($this, 'add_plugin_action_links'));

        // AJAX handlers
        add_action('wp_ajax_pixelflow_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_pixelflow_get_settings', array($this, 'ajax_get_settings'));
        add_action('wp_ajax_pixelflow_save_script_params', array($this, 'ajax_save_script_params'));
        add_action('wp_ajax_pixelflow_remove_script_params', array($this, 'ajax_remove_script_params'));
    }

    /**
     * Load dependencies
     */
    private function load_dependencies()
    {
        // Load WooCommerce integration
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
            $class_options             = get_option('pixelflow_class_options', array());
            $debug_options             = get_option('pixelflow_debug_options', array());
            $script_params               = get_option('pixelflow_script_params', '');

            $settings = array(
                'general_options'       => $pixelflow_general_options,
                'class_options'         => $class_options,
                'debug_options'         => $debug_options,
                'script_params'           => $script_params,
                'nonce'                 => wp_create_nonce('pixelflow_settings_nonce'),
                'ajax_url'              => admin_url('admin-ajax.php'),
                'is_woocommerce_active' => PixelFlow_WooCommerce_Integration::is_woocommerce_active(),
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
        register_setting('pixelflow_settings', 'pixelflow_class_options', array($this, 'sanitize_class_options'));
        register_setting('pixelflow_settings', 'pixelflow_debug_options', array($this, 'sanitize_debug_options'));
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

        return $sanitized;
    }

    /**
     * Sanitize class options (WooCommerce class toggles)
     */
    public function sanitize_class_options($input)
    {
        $sanitized = array();

        // Define all class checkbox options
        $checkbox_options = array(
            // Product classes
            'woo_class_product_container',
            'woo_class_product_name',
            'woo_class_product_price',
            'woo_class_product_quantity',
            'woo_class_product_add_to_cart',
            // Cart classes
            'woo_class_cart_item',
            'woo_class_cart_price',
            'woo_class_cart_checkout_button',
            'woo_class_cart_product_name',
            'woo_class_cart_products_container',
            'woo_class_cart_quantity',
        );

        // Set all checkboxes: 1 if checked, 0 if not
        foreach ($checkbox_options as $option) {
            $sanitized[$option] = isset($input[$option]) && $input[$option] ? 1 : 0;
        }

        return $sanitized;
    }

    /**
     * Inject script into wp_head
     */
    public function inject_script()
    {
        $pixelflow_general_options = get_option('pixelflow_general_options');

        // Only inject if enabled and user role is not excluded
        if (isset($pixelflow_general_options['enabled']) && $pixelflow_general_options['enabled']) {
            // Check if current user's role should be excluded
            if ( ! $this->should_exclude_current_user($pixelflow_general_options)) {
                // Get saved parameters
                $params = get_option('pixelflow_script_params', array());

                // Only enqueue if params exist
                if ( ! empty($params)) {
                    // Extract parameters
                    $pixel_ids        = isset($params['pixelIds']) ? $params['pixelIds'] : array();
                    $site_external_id = isset($params['siteExternalId']) ? $params['siteExternalId'] : '';
                    $api_key          = isset($params['apiKey']) ? $params['apiKey'] : '';
                    $cdn_url          = isset($params['cdnUrl']) ? $params['cdnUrl'] : '';

                    // Check if required params exist
                    if ( ! empty($pixel_ids) && ! empty($site_external_id) && ! empty($api_key) && ! empty($cdn_url)) {
                        // Enqueue the script
                        wp_enqueue_script(
                            $this->tracking_script_handle,
                            esc_url($cdn_url),
                            array(),
                            PIXELFLOW_VERSION,
                            array('in_footer' => false)
                        );

                        // Add async attribute
                        add_filter('script_loader_tag', array($this, 'add_async_attribute'), 10, 2);

                        // Add data attributes via filter
                        add_filter('script_loader_tag', array($this, 'add_tracking_data_attributes'), 10, 2);
                    }
                }
            }
        }

        // Inject debug styles if debug is enabled
        $this->inject_debug_styles();
    }

    /**
     * Add async attribute to pixelflow tracking script
     *
     * @param string $tag    Script tag HTML
     * @param string $handle Script handle
     *
     * @return string Modified script tag
     */
    public function add_async_attribute($tag, $handle)
    {
        if ($this->tracking_script_handle === $handle) {
            return str_replace(' src=', ' async src=', $tag);
        }
        return $tag;
    }

    /**
     * Add tracking data attributes to pixelflow script tag
     *
     * @param string $tag    Script tag HTML
     * @param string $handle Script handle
     *
     * @return string Modified script tag with data attributes
     */
    public function add_tracking_data_attributes($tag, $handle)
    {
        if ($this->tracking_script_handle !== $handle) {
            return $tag;
        }

        // Get saved parameters
        $params = get_option('pixelflow_script_params', array());

        if (empty($params)) {
            return $tag;
        }

        // Extract parameters with defaults
        $pixel_ids        = isset($params['pixelIds']) ? $params['pixelIds'] : array();
        $site_external_id = isset($params['siteExternalId']) ? $params['siteExternalId'] : '';
        $api_key          = isset($params['apiKey']) ? $params['apiKey'] : '';
        $currency         = isset($params['currency']) ? $params['currency'] : 'USD';
        $tracking_urls    = isset($params['trackingUrls']) ? $params['trackingUrls'] : array();
        $api_endpoint     = isset($params['apiEndpoint']) ? $params['apiEndpoint'] : '';
        $enable_meta_pixel = isset($params['enableMetaPixel']) ? $params['enableMetaPixel'] : true;
        $blocking_rules   = isset($params['blockingRules']) ? $params['blockingRules'] : array();

        // Build data attributes
        $data_attrs  = ' data-meta-pixel-ids=\'' . wp_json_encode($pixel_ids, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '\'';
        $data_attrs .= ' data-site-id="' . esc_attr($site_external_id) . '"';
        $data_attrs .= ' data-api-key="' . esc_attr($api_key) . '"';
        $data_attrs .= ' data-currency="' . esc_attr($currency) . '"';
        $data_attrs .= ' data-api-endpoint="' . esc_url($api_endpoint) . '"';
        $data_attrs .= ' data-tracked-urls=\'' . wp_json_encode($tracking_urls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '\'';
        $data_attrs .= ' data-blocking-rules=\'' . wp_json_encode($blocking_rules, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '\'';
        $data_attrs .= ' data-enable-meta-pixel="' . ($enable_meta_pixel ? 'true' : 'false') . '"';

        // Add debug attribute if debug is enabled
        $pixelflow_general_options = get_option('pixelflow_general_options', array());
        if (isset($pixelflow_general_options['debug_enabled']) && $pixelflow_general_options['debug_enabled']) {
            $data_attrs .= ' data-debug="true"';
        }

        // Insert data attributes before the closing >
        $tag = str_replace(' src=', $data_attrs . ' src=', $tag);

        return $tag;
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
     * Inject debug CSS styles for enabled debug classes
     */
    private function inject_debug_styles()
    {
        $pixelflow_general_options = get_option('pixelflow_general_options', array());
        $debug_options             = get_option('pixelflow_debug_options', array());

        // Check if debug is enabled
        if ( ! isset($pixelflow_general_options['debug_enabled']) || ! $pixelflow_general_options['debug_enabled']) {
            return;
        }

        // Map debug options to CSS styles
        $debug_styles = array(
            // Product classes
            'woo_class_product_container'       => '.info-chk-itm-pf { border: 1px solid green !important; background: rgba(0,0,0,0.1) !important; }',
            'woo_class_product_name'            => '.info-itm-name-pf { border: 1px solid red !important; }',
            'woo_class_product_price'           => '.info-itm-prc-pf { border: 1px solid blue !important; }',
            'woo_class_product_quantity'        => '.info-itm-qnty-pf { border: 1px solid orange !important; }',
            'woo_class_product_add_to_cart'     => '.action-btn-cart-005-pf { border: 1px solid #fc0390 !important; }',
            // Cart classes
            'woo_class_cart_item'               => '.info-chk-itm-pf { border: 1px solid green !important; background: rgba(0,0,0,0.1) !important; }',
            'woo_class_cart_price'              => '.info-itm-prc-pf { border: 1px solid blue !important; }',
            'woo_class_cart_checkout_button'    => '.action-btn-buy-004-pf { border: 3px solid #67a174 !important; }',
            'woo_class_cart_products_container' => '.info-chk-itm-ctnr-pf { border: 3px solid #fcdb03 !important; }',
        );

        $enabled_styles = array();

        // Collect enabled debug styles
        foreach ($debug_styles as $option_key => $css) {
            if (isset($debug_options[$option_key]) && $debug_options[$option_key]) {
                $enabled_styles[] = $css;
            }
        }

        // Only output if there are enabled styles
        if ( ! empty($enabled_styles)) {
            $styles = '';
            foreach ($enabled_styles as $style) {
                // CSS styles are hardcoded in $debug_styles array, so they're safe
                // Add .logged-in prefix to each style
                $styles .= '.logged-in ' . $style;
            }

            // Ensure CSS content is safe for output
            // Styles are hardcoded, but validate UTF-8 and remove control characters
            $styles = wp_check_invalid_utf8($styles);
            $styles = preg_replace('/[\x00-\x1F\x7F]/u', '', $styles); // Remove control characters

            $style_key = 'pixelflow-debug';

            // Register and enqueue style
            wp_register_style($style_key, null, array(), PIXELFLOW_VERSION);
            wp_add_inline_style($style_key, $styles);
            wp_enqueue_style($style_key);
        }
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
        </div>
        <div id="pixelflowroot" class="pixelflow-app"></div>
          <?php
          /* <div class="pixelflow-settings-section">
                         <form method="post" action="options.php">
                             <?php
                             settings_fields('pixelflow_settings');
                             do_settings_sections('pixelflow_settings');
                             submit_button();
                             ?>
                         </form>
                     </div>*/ ?>
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
        }

        $pixelflow_general_options = get_option('pixelflow_general_options', array());
        $class_options             = get_option('pixelflow_class_options', array());
        $debug_options             = get_option('pixelflow_debug_options', array());
        $script_params               = get_option('pixelflow_script_params', '');

        wp_send_json_success(array(
            'general_options'       => $pixelflow_general_options,
            'class_options'         => $class_options,
            'debug_options'         => $debug_options,
            'script_params'           => $script_params,
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
        }

        // Get the posted data
        if (isset($_POST['general_options']) && is_array($_POST['general_options'])) {
            $pixelflow_general_options = array_map('sanitize_text_field', wp_unslash($_POST['general_options']));
        } else {
            $pixelflow_general_options = array();
        }

        if (isset($_POST['class_options']) && is_array($_POST['class_options'])) {
            $class_options = array_map('sanitize_text_field', wp_unslash($_POST['class_options']));
        } else {
            $class_options = array();
        }

        if (isset($_POST['debug_options']) && is_array($_POST['debug_options'])) {
            $debug_options = array_map('sanitize_text_field', wp_unslash($_POST['debug_options']));
        } else {
            $debug_options = array();
        }


        // Sanitize and save options
        $sanitized_general_options = $this->sanitize_general_options($pixelflow_general_options);
        $sanitized_class_options   = $this->sanitize_class_options($class_options);
        $sanitized_debug_options   = $this->sanitize_class_options($debug_options);

        update_option('pixelflow_general_options', $sanitized_general_options);
        update_option('pixelflow_class_options', $sanitized_class_options);
        update_option('pixelflow_debug_options', $sanitized_debug_options);

        wp_send_json_success(array(
            'message'         => __('Settings saved successfully', 'pixelflow'),
            'general_options' => $sanitized_general_options,
            'class_options'   => $sanitized_class_options,
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
        }

        // Decode JSON
        $params = json_decode($params_json, true);
        if ($params === null || json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array('message' => __('Invalid JSON payload', 'pixelflow')), 400);
        }

        // Validate required parameters
        $required_keys = array('pixelIds', 'siteExternalId', 'apiKey', 'currency', 'trackingUrls', 'apiEndpoint', 'cdnUrl', 'enableMetaPixel', 'blockingRules');
        foreach ($required_keys as $key) {
            if ( ! isset($params[$key])) {
                // translators: %s is the name of the missing required parameter.
                wp_send_json_error(array('message' => sprintf(__('Missing required parameter: %s', 'pixelflow'), $key)), 400);
            }
        }

        // Sanitize and validate parameters
        $sanitized_params = array(
            'pixelIds'        => array_map('sanitize_text_field', (array) $params['pixelIds']),
            'siteExternalId'   => sanitize_text_field($params['siteExternalId']),
            'apiKey'           => sanitize_text_field($params['apiKey']),
            'currency'         => sanitize_text_field($params['currency']),
            'trackingUrls'     => $this->sanitize_tracking_urls((array) $params['trackingUrls']),
            'apiEndpoint'      => esc_url_raw($params['apiEndpoint']),
            'cdnUrl'           => esc_url_raw($params['cdnUrl']),
            'enableMetaPixel'  => (bool) $params['enableMetaPixel'],
            'blockingRules'    => $this->sanitize_blocking_rules((array) $params['blockingRules']),
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
        }

        // Clear script code and parameters from options
        update_option('pixelflow_script_params', '');
        update_option('pixelflow_script_params', array());

        wp_send_json_success(array(
            'message' => __('Script code and parameters removed successfully', 'pixelflow'),
        ));
    }

    /**
     * Sanitize tracking URLs array
     *
     * @param array $tracking_urls Raw tracking URLs data
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
     * Sanitize blocking rules array
     *
     * @param array $blocking_rules Raw blocking rules data
     * @return array Sanitized blocking rules
     */
    private function sanitize_blocking_rules(array $blocking_rules): array
    {
        $sanitized = array();
        foreach ($blocking_rules as $rule) {
            if ( ! is_array($rule)) {
                continue;
            }
            $sanitized_rule = array();
            foreach ($rule as $key => $value) {
                $sanitized_key                = sanitize_key($key);
                $sanitized_rule[$sanitized_key] = is_bool($value) ? $value : sanitize_text_field($value);
            }
            $sanitized[] = $sanitized_rule;
        }
        return $sanitized;
    }
}

// Initialize the plugin
PixelFlow::get_instance();
