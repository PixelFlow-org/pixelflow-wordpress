<?php
/**
 * Plugin Name: PixelFlow
 * Plugin URI: https://pixelflow.so/
 * Description: PixelFlow Official Plugin for WordPress. Easily Install Meta's Conversions API on Your Website
 * Version: 0.1.5
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
define('PIXELFLOW_VERSION', '0.0.1');
define('PIXELFLOW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PIXELFLOW_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PIXELFLOW_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main PixelFlow class
 */
class PixelFlow
{

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
        add_action('wp_head', array($this, 'inject_script'));
        add_filter('plugin_action_links_' . PIXELFLOW_PLUGIN_BASENAME, array($this, 'add_plugin_action_links'));

        // AJAX handlers
        add_action('wp_ajax_pixelflow_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_pixelflow_get_settings', array($this, 'ajax_get_settings'));
        add_action('wp_ajax_pixelflow_save_script_code', array($this, 'ajax_save_script_code'));
        add_action('wp_ajax_pixelflow_remove_script_code', array($this, 'ajax_remove_script_code'));
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
        // Load text domain
        load_plugin_textdomain('pixelflow', false, dirname(PIXELFLOW_PLUGIN_BASENAME) . '/languages');

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
            $css_path = plugin_dir_path(__FILE__) . 'app/dist/style.css';
            $css_url  = PIXELFLOW_PLUGIN_URL . 'app/dist/style.css';

            $cssVersion = file_exists($css_path) ? filemtime($css_path) : '';

            echo '<link rel="stylesheet" crossorigin href="'
                 . esc_url($css_url . '?ver=' . $cssVersion)
                 . '">' . "\n";


            add_action('admin_footer', function () {
                // Localize settings data and nonce for React app
                $general_options = get_option('pixelflow_general_options', array());
                $class_options   = get_option('pixelflow_class_options', array());
                $debug_options   = get_option('pixelflow_debug_options', array());
                $script_code     = get_option('pixelflow_script_code', '');

                echo '<script>' . "\n";
                echo 'window.pixelflowSettings = ' . wp_json_encode(array(
                        'general_options'       => $general_options,
                        'class_options'         => $class_options,
                        'debug_options'         => $debug_options,
                        'script_code'           => $script_code,
                        'nonce'                 => wp_create_nonce('pixelflow_settings_nonce'),
                        'ajax_url'              => admin_url('admin-ajax.php'),
                        'is_woocommerce_active' => PixelFlow_WooCommerce_Integration::is_woocommerce_active(),
                    )) . ';' . "\n";
                echo '</script>' . "\n";

                $js_path = plugin_dir_path(__FILE__) . 'app/dist/index.js';
                $js_url  = PIXELFLOW_PLUGIN_URL . 'app/dist/index.js';

                $jsVersion = file_exists($js_path) ? filemtime($js_path) : '';

                echo '<script type="module" crossorigin src="'
                     . esc_url($js_url . '?ver=' . $jsVersion)
                     . '"></script>' . "\n";
            });
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
        );

        // Set all checkboxes: 1 if checked, 0 if not
        foreach ($checkbox_options as $option) {
            $sanitized[$option] = isset($input[$option]) && $input[$option] ? 1 : 0;
        }

        // Sanitize excluded_user_roles (array of role keys)

        if (isset($input['excluded_user_roles'])) {
          if(!is_array($input['excluded_user_roles'])){
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
            // Checkout classes
            'woo_class_checkout_form',
            'woo_class_checkout_item',
            'woo_class_checkout_item_name',
            'woo_class_checkout_item_price',
            'woo_class_checkout_item_quantity',
            'woo_class_checkout_total',
            'woo_class_checkout_place_order',
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
        $general_options = get_option('pixelflow_general_options');
        $script_code     = get_option('pixelflow_script_code', '');

        // Only inject if enabled and script code exists and user role is not excluded
        if (isset($general_options['enabled']) && $general_options['enabled'] && ! empty($script_code)) {
            // Check if current user's role should be excluded
            if ( ! $this->should_exclude_current_user($general_options)) {
                echo $script_code;
            }
        }

        // Inject debug styles if debug is enabled
        $this->inject_debug_styles();
    }

    /**
     * Check if the current user's role is in the excluded list
     *
     * @param array $general_options General plugin options
     * @return bool True if user should be excluded, false otherwise
     */
    private function should_exclude_current_user($general_options)
    {
        // If user is not logged in, never exclude (allow script injection for guests)
        if ( ! is_user_logged_in()) {
            return false;
        }

        // Get excluded user roles from settings
        $excluded_roles = isset($general_options['excluded_user_roles']) && is_array($general_options['excluded_user_roles'])
            ? $general_options['excluded_user_roles']
            : array();

        // If no roles are excluded, don't exclude anyone
        if (empty($excluded_roles)) {
            return false;
        }

        // Get current user
        $current_user = wp_get_current_user();

        // Check if any of the user's roles are in the excluded list
        if ( ! empty($current_user->roles)) {
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
        $general_options = get_option('pixelflow_general_options', array());
        $debug_options   = get_option('pixelflow_debug_options', array());

        // Check if debug is enabled
        if ( ! isset($general_options['debug_enabled']) || ! $general_options['debug_enabled']) {
            return;
        }

        // Map debug options to CSS styles
        $debug_styles = array(
            // Product classes
            'woo_class_product_container'      => '.info-chk-itm-pf { border: 1px solid green !important; background: rgba(0,0,0,0.1) !important; }',
            'woo_class_product_name'           => '.info-itm-name-pf { border: 1px solid red !important; }',
            'woo_class_product_price'          => '.info-itm-prc-pf { border: 1px solid blue !important; }',
            'woo_class_product_quantity'       => '.info-itm-qnty-pf { border: 1px solid orange !important; }',
            'woo_class_product_add_to_cart'    => '.action-btn-cart-005-pf { border: 1px solid #fc0390 !important; }',
            // Cart classes
            'woo_class_cart_item'              => '.info-chk-itm-pf { border: 1px solid green !important; background: rgba(0,0,0,0.1) !important; }',
            'woo_class_cart_price'             => '.info-itm-prc-pf { border: 1px solid blue !important; }',
            'woo_class_cart_checkout_button'   => '.action-btn-buy-004-pf { border: 3px solid #67a174 !important; }',
            'woo_class_cart_products_container'   => '.info-chk-itm-ctnr-pf { border: 3px solid #fcdb03 !important; }',
            // Checkout classes
            'woo_class_checkout_form'          => '.info-chk-itm-ctnr-pf { border: 1px solid green !important; }',
            'woo_class_checkout_item'          => '.info-chk-itm-pf { background: rgba(0,0,0,0.1) !important; }',
            'woo_class_checkout_item_name'     => '.info-itm-name-pf { border: 1px solid orange !important; }',
            'woo_class_checkout_item_price'    => '.info-itm-prc-pf { border: 1px solid blue !important; }',
            'woo_class_checkout_item_quantity' => '.info-itm-qnty-pf { border: 1px solid #03adfc !important; }',
            'woo_class_checkout_total'         => '.info-totl-amt-pf { border: 1px solid #b103fc !important; }',
            'woo_class_checkout_place_order'   => '.action-btn-plc-ord-018-pf { border: 3px solid #b01a81 !important; }',
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
            echo "\n<style>\n";
            foreach ($enabled_styles as $style) {
                // Add .logged-in prefix to each style
                $style = str_replace('.info-', '.logged-in .info-', $style);
                $style = str_replace('.action-', '.logged-in .action-', $style);
                echo $style . "\n";
            }
            echo "</style>\n";
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

          if (!isset($wp_roles)) {
              $wp_roles = wp_roles();
          }
          $roles = [];
          foreach ($wp_roles->roles as $role_key => $role) {
              $roles[] = $role_key . "|" . $role["name"];
          }
          $pixelflowUserRoles = apply_filters('pixelflow_user_roles', implode(",", $roles));
          ?>
          <input id="pixelflow-site-id" value="<?php echo $pixelflowSiteId; ?>" type="hidden"/>
          <input id="pixelflow-user-roles" value="<?php echo $pixelflowUserRoles; ?>" type="hidden"/>
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

        $general_options = get_option('pixelflow_general_options', array());
        $class_options   = get_option('pixelflow_class_options', array());
        $debug_options   = get_option('pixelflow_debug_options', array());
        $script_code     = get_option('pixelflow_script_code', '');

        wp_send_json_success(array(
            'general_options'       => $general_options,
            'class_options'         => $class_options,
            'debug_options'         => $debug_options,
            'script_code'           => $script_code,
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
        $general_options = isset($_POST['general_options']) ? $_POST['general_options'] : array();
        $class_options   = isset($_POST['class_options']) ? $_POST['class_options'] : array();
        $debug_options   = isset($_POST['debug_options']) ? $_POST['debug_options'] : array();

        // Sanitize and save options
        $sanitized_general_options = $this->sanitize_general_options($general_options);
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
     * AJAX handler to save script code
     */
    public function ajax_save_script_code()
    {
        // Verify nonce
        check_ajax_referer('pixelflow_settings_nonce', 'nonce');

        // Check user capability
        if ( ! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'pixelflow')), 403);
        }

        // Get the script code
        $script_code = isset($_POST['script_code']) ? wp_unslash($_POST['script_code']) : '';

        // Save script code to separate option
        update_option('pixelflow_script_code', $script_code);

        wp_send_json_success(array(
            'message'     => __('Script code saved successfully', 'pixelflow'),
            'script_code' => $script_code,
        ));
    }

    /**
     * AJAX handler to remove script code
     */
    public function ajax_remove_script_code()
    {
        // Verify nonce
        check_ajax_referer('pixelflow_settings_nonce', 'nonce');

        // Check user capability
        if ( ! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'pixelflow')), 403);
        }

        // Clear script code from separate option
        update_option('pixelflow_script_code', '');

        wp_send_json_success(array(
            'message' => __('Script code removed successfully', 'pixelflow'),
        ));
    }
}

// Initialize the plugin
PixelFlow::get_instance();
