// General plugin options (enabled/disabled toggles)
export interface PixelFlowGeneralOptions {
  enabled: number;
  debug_enabled: number;
  woo_enabled: number;
  woo_purchase_tracking: number;
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
  woo_class_cart_item: number;
  woo_class_cart_price: number;
  woo_class_cart_checkout_button: number;
  woo_class_cart_product_name: number;
  woo_class_cart_products_container: number;
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

// RTK Query types
export interface SettingsResponse {
  general_options: PixelFlowGeneralOptions;
  class_options: PixelFlowClasses;
  debug_options: PixelFlowClasses;
  script_code: string;
  is_woocommerce_active: boolean;
}

export interface SaveSettingsRequest {
  generalOptions: PixelFlowGeneralOptions;
  classOptions: PixelFlowClasses;
  debugOptions: PixelFlowClasses;
}

declare global {
  interface Window {
    pixelflowSettings?: PixelFlowSettings;
  }
}
