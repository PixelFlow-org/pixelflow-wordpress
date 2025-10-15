// General plugin options (enabled/disabled toggles)
export interface PixelFlowGeneralOptions {
  enabled: number;
  debug_enabled: number;
  woo_enabled: number;
  excluded_user_roles: string[]; // Array of role keys to exclude from script injection
}

// User role structure parsed from WordPress
export interface UserRole {
  key: string; // 'administrator', 'editor', etc.
  label: string; // 'Administrator', 'Editor', etc.
}

// WooCommerce class toggles (enable/disable adding specific classes)
export interface PixelFlowClasses {
  // Product classes
  woo_class_product_container: number;
  woo_class_product_name: number;
  woo_class_product_price: number;
  woo_class_product_quantity: number;
  woo_class_product_add_to_cart: number;
  // Cart classes
  woo_class_cart_table: number;
  woo_class_cart_item: number;
  woo_class_cart_price: number;
  woo_class_cart_checkout_button: number;
  // Checkout classes
  woo_class_checkout_form: number;
  woo_class_checkout_item: number;
  woo_class_checkout_item_name: number;
  woo_class_checkout_item_price: number;
  woo_class_checkout_item_quantity: number;
  woo_class_checkout_total: number;
  woo_class_checkout_place_order: number;
}

export interface PixelFlowSettings {
  general_options: PixelFlowGeneralOptions;
  class_options: PixelFlowClasses;
  debug_options: PixelFlowClasses;
  script_code: string; // Managed separately via ajax_save_script_code
  nonce: string;
  ajax_url: string;
  is_woocommerce_active: boolean;
}

export interface WooClassItem {
  key: keyof PixelFlowClasses;
  className: string;
  description: string;
}

declare global {
  interface Window {
    pixelflowSettings?: PixelFlowSettings;
  }
}
